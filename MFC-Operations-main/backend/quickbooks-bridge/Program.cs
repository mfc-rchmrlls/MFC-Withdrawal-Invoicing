using System;
using System.Collections;
using System.Collections.Generic;
using System.Diagnostics;
using System.Globalization;
using System.IO;
using System.Linq;
using System.Net;
using System.Reflection;
using System.Security.Cryptography;
using System.Text;
using System.Text.RegularExpressions;
using System.Threading;
using System.Xml;
using System.Web.Script.Serialization;
using Microsoft.Win32;

internal static class Program
{
    private const string Prefix = "http://127.0.0.1:8765/";
    private const int MaxRequestBytes = 524288;
    private const int MaxResponseBytes = 65536;
    private const int MaxListIds = 50;
    private const int MaxClockSkewSeconds = 30;
    private const int DiagnosticWorkerTimeoutMilliseconds = 10000;
    private const string RequestProcessorProgId = "QBXMLRP2.RequestProcessor";
    private static readonly JavaScriptSerializer Json = new JavaScriptSerializer();

    [STAThread]
    private static int Main(string[] args)
    {
        if (args.Length == 1 && args[0] == "--self-test")
        {
            return RunSelfTests();
        }

        if (args.Length == 1 && args[0] == "--diagnostic-worker")
        {
            return RunDiagnosticWorker();
        }
        if (args.Length == 1 && args[0] == "--test-hang-worker")
        {
            Thread.Sleep(60000);
            return 0;
        }

        var secret = Environment.GetEnvironmentVariable("WQ_QB_BRIDGE_SECRET");
        if (String.IsNullOrEmpty(secret) || Encoding.UTF8.GetByteCount(secret) < 32)
        {
            Console.Error.WriteLine("Bridge unavailable: WQ_QB_BRIDGE_SECRET must contain at least 32 UTF-8 bytes.");
            return 2;
        }

        bool createdNew;
        using (var singleton = new Mutex(false, @"Local\MFCOperationsQuickBooksReadOnlyBridge", out createdNew))
        {
            if (!singleton.WaitOne(0))
            {
                Console.Error.WriteLine("Bridge unavailable: another bridge instance is already running.");
                return 2;
            }

            try
            {
                var cachePath = Path.Combine(
                    Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
                    "MFC-Operations",
                    "QuickBooksBridge",
                    "replay-cache.txt");
                var replayCache = new ReplayCache(cachePath);
                using (var listener = new HttpListener())
                {
                    listener.Prefixes.Add(Prefix);
                    listener.Start();
                    Console.WriteLine("Read-only QuickBooks bridge listening on 127.0.0.1:8765.");
                    Console.WriteLine("QuickBooks transaction-writing operations are not implemented.");
                    while (true)
                    {
                        HttpListenerContext context;
                        try
                        {
                            context = listener.GetContext();
                        }
                        catch (HttpListenerException)
                        {
                            return 0;
                        }
                        HandleRequest(context, secret, replayCache);
                    }
                }
            }
            catch (Exception)
            {
                Console.Error.WriteLine("Bridge stopped: startup or listener failure.");
                return 2;
            }
            finally
            {
                singleton.ReleaseMutex();
            }
        }
    }

    private static void HandleRequest(HttpListenerContext context, string secret, ReplayCache replayCache)
    {
        var requestId = GetSingleHeader(context.Request, "X-MFC-Request-Id") ?? "";
        try
        {
            if (!IPAddress.IsLoopback(context.Request.RemoteEndPoint.Address)
                || !context.Request.LocalEndPoint.Address.Equals(IPAddress.Loopback))
            {
                WriteResponse(context.Response, 403, requestId, "error", "LOOPBACK_ONLY", secret, null);
                return;
            }
            if (!String.Equals(context.Request.HttpMethod, "POST", StringComparison.Ordinal)
                || !String.Equals(context.Request.ContentType, "application/json", StringComparison.OrdinalIgnoreCase)
                || !String.IsNullOrEmpty(context.Request.Headers["Content-Encoding"])
                || context.Request.Url == null
                || !String.IsNullOrEmpty(context.Request.Url.Query)
                || !String.IsNullOrEmpty(context.Request.Url.Fragment))
            {
                WriteResponse(context.Response, 400, requestId, "error", "REQUEST_REJECTED", secret, null);
                return;
            }
            if (context.Request.ContentLength64 > MaxRequestBytes)
            {
                WriteResponse(context.Response, 413, requestId, "error", "REQUEST_TOO_LARGE", secret, null);
                return;
            }

            var rawBody = ReadBoundedBody(context.Request.InputStream, MaxRequestBytes);
            var bodyText = new UTF8Encoding(false, true).GetString(rawBody);
            var request = StrictJsonParser.Parse(bodyText) as Dictionary<string, object>;
            if (request == null)
            {
                WriteResponse(context.Response, 400, requestId, "error", "INVALID_JSON", secret, null);
                return;
            }

            var operation = StringValue(request, "operation");
            var bodyRequestId = StringValue(request, "requestId");
            var timestamp = GetSingleHeader(context.Request, "X-MFC-Timestamp");
            var signature = GetSingleHeader(context.Request, "X-MFC-Signature");
            if (String.IsNullOrEmpty(operation)
                || String.IsNullOrEmpty(timestamp)
                || String.IsNullOrEmpty(signature)
                || !String.Equals(bodyRequestId, requestId, StringComparison.Ordinal)
                || !IsGuid(requestId)
                || !IsFreshTimestamp(timestamp)
                || !IsValidRequestSignature(secret, timestamp, operation, requestId, rawBody, signature))
            {
                WriteResponse(context.Response, 401, requestId, "error", "AUTHENTICATION_FAILED", secret, null);
                return;
            }
            if (!replayCache.TryUse(requestId, DateTimeOffset.UtcNow.ToUnixTimeSeconds()))
            {
                WriteResponse(context.Response, 409, requestId, "error", "REQUEST_REPLAYED", secret, null);
                return;
            }

            var pathOperation = context.Request.Url.AbsolutePath.Trim('/');
            if (!String.Equals(pathOperation, operation, StringComparison.Ordinal)
                || !new[] { "health", "validate", "diagnose" }.Contains(operation, StringComparer.Ordinal))
            {
                WriteResponse(context.Response, 400, requestId, "error", "OPERATION_REJECTED", secret, null);
                return;
            }

            if (operation == "health")
            {
                if (!OnlyKeys(request, "operation", "requestId"))
                {
                    WriteResponse(context.Response, 400, requestId, "error", "UNEXPECTED_FIELD", secret, null);
                    return;
                }
                WriteResponse(context.Response, 200, requestId, "ok", null, secret, HealthData());
                return;
            }
            if (operation == "validate")
            {
                if (!ValidateOnlyRequest(request))
                {
                    WriteResponse(context.Response, 400, requestId, "error", "VALIDATION_REQUEST_REJECTED", secret, null);
                    return;
                }
                WriteResponse(context.Response, 200, requestId, "ok", null, secret, new Dictionary<string, object>
                {
                    { "valid", true },
                    { "validationOnly", true }
                });
                return;
            }

            string entity;
            List<string> listIds;
            if (!ValidateDiagnosticRequest(request, out entity, out listIds))
            {
                WriteResponse(context.Response, 400, requestId, "error", "DIAGNOSTIC_REQUEST_REJECTED", secret, null);
                return;
            }
            var diagnostic = RunDiagnosticIsolated(entity, listIds);
            WriteResponse(
                context.Response,
                diagnostic.StatusCode,
                requestId,
                diagnostic.Status,
                diagnostic.Code,
                secret,
                diagnostic.Data);
        }
        catch (InvalidDataException)
        {
            WriteResponse(context.Response, 413, requestId, "error", "REQUEST_TOO_LARGE", secret, null);
        }
        catch (FormatException)
        {
            WriteResponse(context.Response, 400, requestId, "error", "INVALID_JSON", secret, null);
        }
        catch (Exception)
        {
            WriteResponse(context.Response, 500, requestId, "error", "BRIDGE_INTERNAL_ERROR", secret, null);
        }
    }

    private static Dictionary<string, object> HealthData()
    {
        var processor = Type.GetTypeFromProgID(RequestProcessorProgId, false);
        var processes = Process.GetProcessesByName("QBW").Concat(Process.GetProcessesByName("QBW32")).ToArray();
        bool quickBooksRunning = processes.Length > 0;
        foreach (var process in processes)
        {
            process.Dispose();
        }
        return BuildHealthData(processor != null, quickBooksRunning);
    }

    private static Dictionary<string, object> BuildHealthData(bool sdkRegistered, bool quickBooksRunning)
    {
        return new Dictionary<string, object>
        {
            { "sdkRegistered", sdkRegistered },
            { "clrVersion", Environment.Version.ToString() },
            { "dotNetFrameworkRelease", ReadDotNetFrameworkRelease() },
            { "quickBooksProcessRunning", quickBooksRunning },
            { "companySessionChecked", false },
            { "companySessionReady", null },
            { "companyFingerprint", null },
            { "diagnosticCode", !sdkRegistered ? "SDK_NOT_REGISTERED" : (quickBooksRunning ? null : "QUICKBOOKS_NOT_RUNNING") }
        };
    }

    private static string ReadDotNetFrameworkRelease()
    {
        try
        {
            using (var key = Registry.LocalMachine.OpenSubKey(@"SOFTWARE\Microsoft\NET Framework Setup\NDP\v4\Full"))
            {
                var release = key == null ? null : key.GetValue("Release");
                return release == null ? null : Convert.ToString(release, CultureInfo.InvariantCulture);
            }
        }
        catch (Exception)
        {
            return null;
        }
    }

    private static bool ValidateOnlyRequest(Dictionary<string, object> request)
    {
        if (!OnlyKeys(request, "protocolVersion", "requestId", "operation", "validationOnly",
            "idempotencyKey", "transactionType", "source", "transaction")
            || !HasKeys(request, "protocolVersion", "requestId", "operation", "validationOnly",
                "idempotencyKey", "transactionType", "source", "transaction")
            || !String.Equals(StringValue(request, "protocolVersion"), "1", StringComparison.Ordinal)
            || !String.Equals(StringValue(request, "operation"), "validate", StringComparison.Ordinal)
            || !BooleanValue(request, "validationOnly")
            || !IsNullValue(request, "idempotencyKey")
            || !IsGuid(StringValue(request, "requestId"))
            || !new[] { "invoice", "sales_order" }.Contains(StringValue(request, "transactionType"), StringComparer.Ordinal))
        {
            return false;
        }

        var source = ObjectValue(request, "source");
        var transaction = ObjectValue(request, "transaction");
        if (source == null || transaction == null
            || !OnlyKeys(source, "id", "revision")
            || !HasKeys(source, "id", "revision")
            || !(source["id"] is decimal)
            || (decimal)source["id"] < 1m
            || Decimal.Truncate((decimal)source["id"]) != (decimal)source["id"]
            || !Regex.IsMatch(StringValue(source, "revision") ?? "", "^[a-f0-9]{64}$")
            || !OnlyKeys(transaction, "customer", "txnDate", "refNumber", "poNumber", "memo", "terms", "class",
                "billAddress", "shipAddress", "lines", "expectedSubtotal", "expectedTax", "expectedTotal"))
        {
            return false;
        }
        if (!HasKeys(transaction, "customer", "txnDate", "refNumber", "poNumber", "memo", "lines",
                "expectedSubtotal", "expectedTax", "expectedTotal")
            || !IsValidDate(StringValue(transaction, "txnDate"))
            || !IsBoundedString(StringValue(transaction, "refNumber"), 100, false)
            || !IsBoundedStringValue(transaction, "poNumber", 100)
            || !IsBoundedStringValue(transaction, "memo", 65535)
            || !IsMoneyString(StringValue(transaction, "expectedSubtotal"))
            || !IsNullValue(transaction, "expectedTax")
            || !IsNullValue(transaction, "expectedTotal")
            || (transaction.ContainsKey("terms") && !IsBoundedStringValue(transaction, "terms", 100))
            || (transaction.ContainsKey("class") && !IsBoundedStringValue(transaction, "class", 100))
            || (transaction.ContainsKey("billAddress") && !ValidateAddress(transaction["billAddress"]))
            || (transaction.ContainsKey("shipAddress") && !ValidateAddress(transaction["shipAddress"])))
        {
            return false;
        }
        var customer = ObjectValue(transaction, "customer");
        var lines = ArrayValue(transaction, "lines");
        if (customer == null || !OnlyKeys(customer, "listId") || !HasKeys(customer, "listId")
            || !IsValidListId(StringValue(customer, "listId"))
            || lines == null || lines.Count < 1 || lines.Count > 500)
        {
            return false;
        }
        decimal subtotal = 0m;
        foreach (var value in lines)
        {
            var line = value as Dictionary<string, object>;
            if (line == null
                || !OnlyKeys(line, "item", "description", "quantity", "rate", "expectedAmount", "salesTaxCode",
                    "site", "lotNumber", "expirationDate", "unitOfMeasure")
                || !HasKeys(line, "item", "description", "quantity", "rate", "expectedAmount", "salesTaxCode"))
            {
                return false;
            }
            var item = ObjectValue(line, "item");
            var taxCode = ObjectValue(line, "salesTaxCode");
            if (item == null || taxCode == null
                || !OnlyKeys(item, "listId") || !OnlyKeys(taxCode, "listId")
                || !HasKeys(item, "listId") || !HasKeys(taxCode, "listId")
                || !IsValidListId(StringValue(item, "listId"))
                || !IsValidListId(StringValue(taxCode, "listId"))
                || !IsBoundedString(StringValue(line, "description"), 255, false)
                || !IsPositiveQuantity(StringValue(line, "quantity"))
                || !IsMoneyString(StringValue(line, "rate"))
                || !IsMoneyString(StringValue(line, "expectedAmount"))
                || !IsNullOrEmptyIfPresent(line, "site")
                || !IsNullOrEmptyIfPresent(line, "lotNumber")
                || !IsNullOrEmptyIfPresent(line, "expirationDate")
                || !IsNullOrEmptyIfPresent(line, "unitOfMeasure"))
            {
                return false;
            }
            decimal quantity = Decimal.Parse(StringValue(line, "quantity"), CultureInfo.InvariantCulture);
            decimal rate = Decimal.Parse(StringValue(line, "rate"), CultureInfo.InvariantCulture);
            decimal amount = Decimal.Parse(StringValue(line, "expectedAmount"), CultureInfo.InvariantCulture);
            if (quantity * rate != amount)
            {
                return false;
            }
            subtotal += amount;
        }
        decimal expectedSubtotal = Decimal.Parse(StringValue(transaction, "expectedSubtotal"), CultureInfo.InvariantCulture);
        return subtotal == expectedSubtotal;
    }

    private static bool ValidateDiagnosticRequest(
        Dictionary<string, object> request,
        out string entity,
        out List<string> listIds)
    {
        entity = StringValue(request, "entity");
        listIds = null;
        if (!OnlyKeys(request, "operation", "requestId", "entity", "listIds")
            || !String.Equals(StringValue(request, "operation"), "diagnose", StringComparison.Ordinal)
            || !new[] { "customer", "item", "tax_code", "tax_item", "inventory_site" }.Contains(entity, StringComparer.Ordinal))
        {
            return false;
        }
        var values = ArrayValue(request, "listIds");
        if (values == null || values.Count < 1 || values.Count > MaxListIds)
        {
            return false;
        }
        listIds = new List<string>(values.Count);
        foreach (var value in values)
        {
            var id = value as string;
            if (String.IsNullOrWhiteSpace(id) || id.Length > 100
                || id.Any(c => !(Char.IsLetterOrDigit(c) || c == '-' || c == '_' || c == ':' || c == '.')))
            {
                return false;
            }
            if (listIds.Contains(id, StringComparer.Ordinal))
            {
                return false;
            }
            listIds.Add(id);
        }
        return true;
    }

    private static DiagnosticResult RunDiagnostic(string entity, List<string> listIds, string secret)
    {
        object requestProcessor = null;
        dynamic processor = null;
        string ticket = null;
        bool connectionOpen = false;
        bool sessionOpen = false;
        var failureCode = "SDK_UNAVAILABLE";
        try
        {
            var processorType = Type.GetTypeFromProgID(RequestProcessorProgId, true);
            requestProcessor = Activator.CreateInstance(processorType);
            processor = requestProcessor;
            failureCode = "SDK_CONNECTION_FAILED";
            processor.OpenConnection("MFCOperationsReadOnlyBridge", "MFC Operations read-only diagnostics");
            connectionOpen = true;
            failureCode = "COMPANY_SESSION_UNAVAILABLE";
            ticket = (string)processor.BeginSession("", 2);
            sessionOpen = true;

            failureCode = "COMPANY_ID_UNAVAILABLE";
            var companyPath = (string)processor.GetCurrentCompanyFileName(ticket);
            if (String.IsNullOrWhiteSpace(companyPath))
            {
                return DiagnosticResult.Unavailable("COMPANY_SESSION_UNAVAILABLE");
            }

            failureCode = "QBXML_VERSION_UNSUPPORTED";
            var supported = processor.QBXMLVersionsForSession(ticket) as IEnumerable;
            var version = SelectQbXmlVersion(supported);
            if (version == null)
            {
                return DiagnosticResult.Unavailable("QBXML_VERSION_UNSUPPORTED");
            }

            failureCode = "QBXML_QUERY_FAILED";
            var records = QueryRecords(processor, ticket, entity, listIds, version);
            var companyFingerprint = HmacHex(
                secret,
                Encoding.UTF8.GetBytes("company-file-path\n" + Path.GetFullPath(companyPath).ToUpperInvariant()));
            return DiagnosticResult.Ready(entity, records, companyFingerprint, version);
        }
        catch (Exception)
        {
            return DiagnosticResult.Unavailable(failureCode);
        }
        finally
        {
            try
            {
                if (sessionOpen && processor != null)
                {
                    processor.EndSession(ticket);
                }
            }
            catch (Exception) { }
            try
            {
                if (connectionOpen && processor != null)
                {
                    processor.CloseConnection();
                }
            }
            catch (Exception) { }
            if (requestProcessor != null && MarshalIsComObject(requestProcessor))
            {
                try { System.Runtime.InteropServices.Marshal.FinalReleaseComObject(requestProcessor); }
                catch (Exception) { }
            }
        }
    }

    private static DiagnosticResult RunDiagnosticIsolated(string entity, List<string> listIds)
    {
        if (Process.GetProcessesByName("QBW").Length == 0 && Process.GetProcessesByName("QBW32").Length == 0)
        {
            return DiagnosticResult.Unavailable("QUICKBOOKS_NOT_RUNNING");
        }

        Process worker = null;
        try
        {
            var start = new ProcessStartInfo
            {
                FileName = Assembly.GetExecutingAssembly().Location,
                Arguments = "--diagnostic-worker",
                UseShellExecute = false,
                CreateNoWindow = true,
                RedirectStandardInput = true,
                RedirectStandardOutput = true,
                RedirectStandardError = true
            };
            worker = Process.Start(start);
            if (worker == null)
            {
                return DiagnosticResult.Unavailable("SDK_WORKER_UNAVAILABLE");
            }
            worker.StandardInput.Write(Json.Serialize(new Dictionary<string, object>
            {
                { "entity", entity },
                { "listIds", listIds }
            }));
            worker.StandardInput.Close();
            var outputTask = worker.StandardOutput.ReadToEndAsync();
            var errorTask = worker.StandardError.ReadToEndAsync();
            if (!WaitForWorker(worker, DiagnosticWorkerTimeoutMilliseconds))
            {
                return DiagnosticResult.Unavailable("SDK_TIMEOUT");
            }
            var output = outputTask.Result;
            var ignoredError = errorTask.Result;
            var result = StrictJsonParser.Parse(output) as Dictionary<string, object>;
            if (result == null)
            {
                return DiagnosticResult.Unavailable("SDK_WORKER_INVALID_RESPONSE");
            }
            var statusCode = result.ContainsKey("httpStatus") && result["httpStatus"] is decimal
                ? Decimal.ToInt32((decimal)result["httpStatus"])
                : 503;
            var status = StringValue(result, "status");
            return new DiagnosticResult
            {
                StatusCode = statusCode,
                Status = status == "ok" ? "ok" : "unavailable",
                Code = StringValue(result, "diagnosticCode"),
                Data = ObjectValue(result, "data")
            };
        }
        catch (Exception)
        {
            return DiagnosticResult.Unavailable("SDK_WORKER_UNAVAILABLE");
        }
        finally
        {
            if (worker != null)
            {
                try { worker.Dispose(); }
                catch (Exception) { }
            }
        }
    }

    private static int RunDiagnosticWorker()
    {
        try
        {
            var request = StrictJsonParser.Parse(Console.In.ReadToEnd()) as Dictionary<string, object>;
            if (request == null || !OnlyKeys(request, "entity", "listIds"))
            {
                throw new FormatException("Invalid worker request.");
            }
            var entity = StringValue(request, "entity");
            var values = ArrayValue(request, "listIds");
            if (String.IsNullOrEmpty(entity) || values == null || values.Count < 1 || values.Count > MaxListIds)
            {
                throw new FormatException("Invalid worker request.");
            }
            var listIds = values.Select(value => value as string).ToList();
            if (listIds.Any(value => String.IsNullOrEmpty(value)))
            {
                throw new FormatException("Invalid worker request.");
            }
            var secret = Environment.GetEnvironmentVariable("WQ_QB_BRIDGE_SECRET") ?? "";
            var result = RunDiagnostic(entity, listIds, secret);
            Console.Out.Write(Json.Serialize(new Dictionary<string, object>
            {
                { "httpStatus", result.StatusCode },
                { "status", result.Status },
                { "diagnosticCode", result.Code },
                { "data", result.Data }
            }));
            return result.Status == "ok" ? 0 : 1;
        }
        catch (Exception)
        {
            Console.Out.Write(Json.Serialize(new Dictionary<string, object>
            {
                { "httpStatus", 503 },
                { "status", "unavailable" },
                { "diagnosticCode", "QB_SESSION_UNAVAILABLE" }
            }));
            return 1;
        }
    }

    private static List<Dictionary<string, object>> QueryRecords(
        dynamic processor,
        string ticket,
        string entity,
        List<string> listIds,
        string version)
    {
        var config = QueryConfig.For(entity);
        var records = new Dictionary<string, Dictionary<string, object>>(StringComparer.Ordinal);
        foreach (var listId in listIds)
        {
            var response = (string)processor.ProcessRequest(ticket, BuildQuery(version, config.RequestName, listId));
            var document = LoadSafeXml(response);
            var queryResponse = document.SelectSingleNode("//" + config.ResponseName);
            if (queryResponse == null
                || !String.Equals(queryResponse.Attributes["statusCode"] == null ? null : queryResponse.Attributes["statusCode"].Value, "0", StringComparison.Ordinal))
            {
                throw new InvalidDataException("QuickBooks returned an unsuccessful read-only query.");
            }
            foreach (XmlNode node in queryResponse.ChildNodes)
            {
                if (node.NodeType != XmlNodeType.Element || !node.LocalName.EndsWith("Ret", StringComparison.Ordinal))
                {
                    continue;
                }
                var returnedId = ChildText(node, "ListID");
                if (!String.Equals(returnedId, listId, StringComparison.Ordinal))
                {
                    continue;
                }
                var name = ChildText(node, "FullName") ?? ChildText(node, "Name") ?? "";
                var activeText = ChildText(node, "IsActive");
                bool active;
                if (!Boolean.TryParse(activeText, out active))
                {
                    active = true;
                }
                records[listId] = new Dictionary<string, object>
                {
                    { "listId", returnedId },
                    { "name", name },
                    { "active", active },
                    { "found", true }
                };
            }
        }
        return listIds.Select(id => records.ContainsKey(id)
            ? records[id]
            : new Dictionary<string, object> { { "listId", id }, { "found", false } }).ToList();
    }

    private static XmlDocument LoadSafeXml(string response)
    {
        if (String.IsNullOrEmpty(response) || Encoding.UTF8.GetByteCount(response) > 2097152)
        {
            throw new InvalidDataException("QuickBooks returned an invalid response.");
        }
        var settings = new XmlReaderSettings
        {
            DtdProcessing = DtdProcessing.Prohibit,
            XmlResolver = null,
            MaxCharactersInDocument = 2097152,
            MaxCharactersFromEntities = 0
        };
        var document = new XmlDocument { XmlResolver = null };
        using (var textReader = new StringReader(response))
        using (var reader = XmlReader.Create(textReader, settings))
        {
            document.Load(reader);
        }
        return document;
    }

    private static string BuildQuery(string version, string requestName, string listId)
    {
        var settings = new XmlWriterSettings { OmitXmlDeclaration = true, Encoding = new UTF8Encoding(false), Indent = false };
        var builder = new StringBuilder();
        builder.Append("<?xml version=\"1.0\" encoding=\"utf-8\"?><?qbxml version=\"")
            .Append(version)
            .Append("\"?>");
        using (var writer = XmlWriter.Create(builder, settings))
        {
            writer.WriteStartElement("QBXML");
            writer.WriteStartElement("QBXMLMsgsRq");
            writer.WriteAttributeString("onError", "stopOnError");
            writer.WriteStartElement(requestName);
            writer.WriteAttributeString("requestID", "1");
            writer.WriteElementString("ListID", listId);
            writer.WriteEndElement();
            writer.WriteEndElement();
            writer.WriteEndElement();
        }
        return builder.ToString();
    }

    private static string SelectQbXmlVersion(IEnumerable values)
    {
        if (values == null)
        {
            return null;
        }
        var versions = new List<Tuple<decimal, string>>();
        foreach (var item in values)
        {
            var value = item as string;
            decimal parsed;
            if (!String.IsNullOrEmpty(value) && Decimal.TryParse(value, NumberStyles.AllowDecimalPoint, CultureInfo.InvariantCulture, out parsed)
                && parsed >= 10m && parsed <= 16m)
            {
                versions.Add(Tuple.Create(parsed, value));
            }
        }
        return versions.OrderByDescending(v => v.Item1).Select(v => v.Item2).FirstOrDefault();
    }

    private static string ChildText(XmlNode parent, string localName)
    {
        var child = parent.SelectSingleNode("./*[local-name()='" + localName + "']");
        return child == null ? null : child.InnerText;
    }

    private static byte[] ReadBoundedBody(Stream stream, int maximum)
    {
        using (var output = new MemoryStream())
        {
            var buffer = new byte[8192];
            int count;
            while ((count = stream.Read(buffer, 0, buffer.Length)) > 0)
            {
                if (output.Length + count > maximum)
                {
                    throw new InvalidDataException("Request body exceeded the limit.");
                }
                output.Write(buffer, 0, count);
            }
            return output.ToArray();
        }
    }

    private static string GetSingleHeader(HttpListenerRequest request, string name)
    {
        var values = request.Headers.GetValues(name);
        return values == null || values.Length != 1 ? null : values[0];
    }

    private static bool IsFreshTimestamp(string timestamp)
    {
        long unix;
        return timestamp.Length == 10
            && timestamp.All(c => Char.IsDigit(c))
            && Int64.TryParse(timestamp, NumberStyles.None, CultureInfo.InvariantCulture, out unix)
            && Math.Abs(DateTimeOffset.UtcNow.ToUnixTimeSeconds() - unix) <= MaxClockSkewSeconds;
    }

    private static bool IsValidRequestSignature(string secret, string timestamp, string operation, string requestId, byte[] body, string signature)
    {
        var expected = HmacHex(secret, Encoding.UTF8.GetBytes(
            timestamp + "\n" + operation + "\n" + requestId + "\n" + Sha256Hex(body)));
        return signature.Length == 64
            && signature.All(Uri.IsHexDigit)
            && ConstantTimeEquals(expected, signature.ToLowerInvariant());
    }

    private static bool IsValidResponseSignature(
        string secret,
        string timestamp,
        string requestId,
        int statusCode,
        byte[] body,
        string signature)
    {
        var expected = HmacHex(secret, Encoding.UTF8.GetBytes(
            timestamp + "\nresponse\n" + requestId + "\n" + statusCode.ToString(CultureInfo.InvariantCulture) + "\n" + Sha256Hex(body)));
        return signature.Length == 64 && ConstantTimeEquals(expected, signature.ToLowerInvariant());
    }

    private static bool IsResponseWithinLimit(byte[] body)
    {
        return body != null && body.Length <= MaxResponseBytes;
    }

    private static bool WaitForWorker(Process process, int timeoutMilliseconds)
    {
        if (process.WaitForExit(timeoutMilliseconds))
        {
            return true;
        }
        process.Kill();
        process.WaitForExit();
        return false;
    }

    private static void WriteResponse(
        HttpListenerResponse response,
        int statusCode,
        string requestId,
        string status,
        string diagnosticCode,
        string secret,
        Dictionary<string, object> data)
    {
        var payload = new Dictionary<string, object>
        {
            { "requestId", requestId },
            { "status", status }
        };
        if (!String.IsNullOrEmpty(diagnosticCode))
        {
            payload["diagnosticCode"] = diagnosticCode;
        }
        if (data != null)
        {
            foreach (var entry in data)
            {
                payload[entry.Key] = entry.Value;
            }
        }
        var body = Encoding.UTF8.GetBytes(Json.Serialize(payload));
        if (body.Length > MaxResponseBytes)
        {
            body = Encoding.UTF8.GetBytes(Json.Serialize(new Dictionary<string, object>
            {
                { "requestId", requestId },
                { "status", "error" },
                { "diagnosticCode", "RESPONSE_TOO_LARGE" }
            }));
            statusCode = 500;
        }
        var timestamp = DateTimeOffset.UtcNow.ToUnixTimeSeconds().ToString(CultureInfo.InvariantCulture);
        var signature = HmacHex(secret, Encoding.UTF8.GetBytes(
            timestamp + "\nresponse\n" + requestId + "\n" + statusCode.ToString(CultureInfo.InvariantCulture) + "\n" + Sha256Hex(body)));
        response.StatusCode = statusCode;
        response.ContentType = "application/json; charset=utf-8";
        response.Headers["X-MFC-Timestamp"] = timestamp;
        response.Headers["X-MFC-Signature"] = signature;
        response.ContentLength64 = body.Length;
        try
        {
            response.OutputStream.Write(body, 0, body.Length);
        }
        finally
        {
            response.Close();
        }
    }

    private static string Sha256Hex(byte[] value)
    {
        using (var sha = SHA256.Create())
        {
            return ToHex(sha.ComputeHash(value));
        }
    }

    private static string HmacHex(string secret, byte[] value)
    {
        using (var hmac = new HMACSHA256(Encoding.UTF8.GetBytes(secret)))
        {
            return ToHex(hmac.ComputeHash(value));
        }
    }

    private static string ToHex(byte[] value)
    {
        return BitConverter.ToString(value).Replace("-", "").ToLowerInvariant();
    }

    private static bool ConstantTimeEquals(string left, string right)
    {
        if (left.Length != right.Length)
        {
            return false;
        }
        var difference = 0;
        for (var i = 0; i < left.Length; i++)
        {
            difference |= left[i] ^ right[i];
        }
        return difference == 0;
    }

    private static bool MarshalIsComObject(object value)
    {
        return System.Runtime.InteropServices.Marshal.IsComObject(value);
    }

    private static bool IsGuid(string value)
    {
        Guid parsed;
        return Guid.TryParseExact(value, "D", out parsed);
    }

    private static Dictionary<string, object> ObjectValue(Dictionary<string, object> source, string key)
    {
        object value;
        return source != null && source.TryGetValue(key, out value) ? value as Dictionary<string, object> : null;
    }

    private static List<object> ArrayValue(Dictionary<string, object> source, string key)
    {
        object value;
        return source != null && source.TryGetValue(key, out value) ? value as List<object> : null;
    }

    private static string StringValue(Dictionary<string, object> source, string key)
    {
        object value;
        return source != null && source.TryGetValue(key, out value) ? value as string : null;
    }

    private static bool BooleanValue(Dictionary<string, object> source, string key)
    {
        object value;
        return source != null && source.TryGetValue(key, out value) && value is bool && (bool)value;
    }

    private static bool IsNullValue(Dictionary<string, object> source, string key)
    {
        object value;
        return source != null && source.TryGetValue(key, out value) && value == null;
    }

    private static bool OnlyKeys(Dictionary<string, object> source, params string[] keys)
    {
        return source != null && source.Keys.All(k => keys.Contains(k, StringComparer.Ordinal));
    }

    private static bool HasKeys(Dictionary<string, object> source, params string[] keys)
    {
        return source != null && keys.All(source.ContainsKey);
    }

    private static bool IsValidListId(string value)
    {
        return !String.IsNullOrWhiteSpace(value)
            && value.Length <= 100
            && value.All(c => Char.IsLetterOrDigit(c) || c == '-' || c == '_' || c == ':' || c == '.');
    }

    private static bool IsBoundedString(string value, int maximumLength, bool allowEmpty)
    {
        return value != null && value.Length <= maximumLength && (allowEmpty || !String.IsNullOrWhiteSpace(value));
    }

    private static bool IsBoundedStringValue(Dictionary<string, object> source, string key, int maximumLength)
    {
        object value;
        return source.TryGetValue(key, out value) && (value == null || IsBoundedString(value as string, maximumLength, true));
    }

    private static bool IsMoneyString(string value)
    {
        decimal parsed;
        return value != null
            && Regex.IsMatch(value, "^\\d{1,10}(?:\\.\\d{1,2})?$")
            && Decimal.TryParse(value, NumberStyles.AllowDecimalPoint, CultureInfo.InvariantCulture, out parsed);
    }

    private static bool IsPositiveQuantity(string value)
    {
        return value != null && Regex.IsMatch(value, "^[1-9]\\d{0,8}$");
    }

    private static bool IsValidDate(string value)
    {
        DateTime parsed;
        return value != null && DateTime.TryParseExact(
            value,
            "yyyy-MM-dd",
            CultureInfo.InvariantCulture,
            DateTimeStyles.None,
            out parsed);
    }

    private static bool IsNullOrEmptyIfPresent(Dictionary<string, object> source, string key)
    {
        object value;
        return !source.TryGetValue(key, out value) || value == null || (value is string && (string)value == "");
    }

    private static bool ValidateAddress(object value)
    {
        if (value == null)
        {
            return true;
        }
        var address = value as Dictionary<string, object>;
        if (address == null || !OnlyKeys(address, "addr1", "addr2", "addr3", "addr4", "addr5", "city", "state", "postalCode", "country"))
        {
            return false;
        }
        foreach (var entry in address.Values)
        {
            if (entry != null && !(entry is string && ((string)entry).Length <= 41))
            {
                return false;
            }
        }
        return true;
    }

    private static int RunSelfTests()
    {
        var failures = 0;
        Action<bool, string> check = (condition, label) =>
        {
            Console.WriteLine((condition ? "PASS: " : "FAIL: ") + label);
            if (!condition) failures++;
        };

        var valid = "{\"operation\":\"health\",\"requestId\":\"11111111-1111-4111-8111-111111111111\"}";
        check(StrictJsonParser.Parse(valid) is Dictionary<string, object>, "strict JSON parser accepts the PHP health request shape");
        var duplicateRejected = false;
        try { StrictJsonParser.Parse("{\"operation\":\"health\",\"operation\":\"diagnose\"}"); }
        catch (FormatException) { duplicateRejected = true; }
        check(duplicateRejected, "duplicate JSON properties are rejected");
        var malformedRejected = false;
        try { StrictJsonParser.Parse("{malformed"); }
        catch (FormatException) { malformedRejected = true; }
        check(malformedRejected, "malformed JSON is rejected");
        var malformedXmlRejected = false;
        try { LoadSafeXml("<QBXML><broken></QBXML>"); }
        catch (XmlException) { malformedXmlRejected = true; }
        check(malformedXmlRejected, "malformed qbXML responses are rejected");
        var dtdRejected = false;
        try { LoadSafeXml("<!DOCTYPE x [<!ENTITY e SYSTEM \"file:///C:/Windows/win.ini\">]><x>&e;</x>"); }
        catch (XmlException) { dtdRejected = true; }
        check(dtdRejected, "qbXML DTD and external entity payloads are rejected");
        check(IPAddress.IsLoopback(IPAddress.Parse("127.0.0.1")) && !IPAddress.IsLoopback(IPAddress.Parse("192.0.2.1")),
            "loopback client address is accepted and non-loopback address is rejected");
        check(Prefix == "http://127.0.0.1:8765/", "listener binds only to IPv4 loopback");
        check(String.Equals(
            StringValue(BuildHealthData(false, false), "diagnosticCode"),
            "SDK_NOT_REGISTERED",
            StringComparison.Ordinal),
            "health reports an unavailable SDK registration without claiming readiness");

        var secret = new string('k', 40);
        var body = Encoding.UTF8.GetBytes(valid);
        var signature = HmacHex(secret, Encoding.UTF8.GetBytes(
            "1792744396\nhealth\n11111111-1111-4111-8111-111111111111\n" + Sha256Hex(body)));
        check(IsValidRequestSignature(secret, "1792744396", "health", "11111111-1111-4111-8111-111111111111", body, signature),
            "request HMAC canonicalization matches the PHP client contract");
        check(!IsValidRequestSignature(secret, "1792744396", "health", "11111111-1111-4111-8111-111111111111", body, new string('0', 64)),
            "invalid request signatures fail closed");
        var responseTimestamp = DateTimeOffset.UtcNow.ToUnixTimeSeconds().ToString(CultureInfo.InvariantCulture);
        var responseBody = Encoding.UTF8.GetBytes("{\"requestId\":\"11111111-1111-4111-8111-111111111111\",\"status\":\"ok\"}");
        var responseSignature = HmacHex(secret, Encoding.UTF8.GetBytes(
            responseTimestamp + "\nresponse\n11111111-1111-4111-8111-111111111111\n200\n" + Sha256Hex(responseBody)));
        check(IsValidResponseSignature(
            secret, responseTimestamp, "11111111-1111-4111-8111-111111111111", 200, responseBody, responseSignature),
            "response HMAC canonicalization matches the PHP client contract");
        check(!IsValidResponseSignature(
            secret, responseTimestamp, "11111111-1111-4111-8111-111111111111", 200, responseBody, new string('0', 64)),
            "invalid response signatures fail closed");
        check(!IsResponseWithinLimit(new byte[MaxResponseBytes + 1]), "oversized responses are rejected before sending");
        var oversizedRequestRejected = false;
        try { ReadBoundedBody(new MemoryStream(new byte[MaxRequestBytes + 1]), MaxRequestBytes); }
        catch (InvalidDataException) { oversizedRequestRejected = true; }
        check(oversizedRequestRejected, "oversized requests are rejected before JSON parsing");
        check(!IsFreshTimestamp("1000000000"), "stale request timestamps are rejected");
        check(!IsFreshTimestamp("not-a-time"), "malformed request timestamps are rejected");

        var replayFile = Path.Combine(Path.GetTempPath(), "mfc-qb-bridge-selftest-" + Guid.NewGuid().ToString("N") + ".txt");
        try
        {
            var cache = new ReplayCache(replayFile);
            const string nonce = "22222222-2222-4222-8222-222222222222";
            check(cache.TryUse(nonce, DateTimeOffset.UtcNow.ToUnixTimeSeconds()), "fresh request ID is accepted");
            check(!cache.TryUse(nonce, DateTimeOffset.UtcNow.ToUnixTimeSeconds()), "replayed request ID is rejected");
        }
        finally
        {
            if (File.Exists(replayFile)) File.Delete(replayFile);
        }
        var version = SelectQbXmlVersion(new[] { "7.0", "13.0", "16.0" });
        check(version == "16.0", "qbXML version is selected only from the RequestProcessor session capabilities");
        var query = BuildQuery("16.0", "CustomerQueryRq", "CUST&LIST");
        check(query.Contains("<?qbxml version=\"16.0\"?>")
            && query.Contains("<CustomerQueryRq")
            && query.Contains("<ListID>CUST&amp;LIST</ListID>")
            && !query.Contains("AddRq"),
            "qbXML diagnostics build escaped ListID-filtered query-only requests");
        var unsupportedOperation = new Dictionary<string, object>
        {
            { "operation", "InvoiceAdd" },
            { "entity", "customer" },
            { "listIds", new List<object> { "CUST-1" } }
        };
        string ignoredEntity;
        List<string> ignoredIds;
        check(!ValidateDiagnosticRequest(unsupportedOperation, out ignoredEntity, out ignoredIds),
            "transaction-add and unknown operations cannot reach QuickBooks diagnostics");
        var validValidationRequest = new Dictionary<string, object>
        {
            { "protocolVersion", "1" },
            { "requestId", "88888888-8888-4888-8888-888888888888" },
            { "operation", "validate" },
            { "validationOnly", true },
            { "idempotencyKey", null },
            { "transactionType", "invoice" },
            { "source", new Dictionary<string, object> { { "id", 1m }, { "revision", new string('a', 64) } } },
            { "transaction", new Dictionary<string, object>
                {
                    { "customer", new Dictionary<string, object> { { "listId", "CUST-1" } } },
                    { "txnDate", "2026-10-23" },
                    { "refNumber", "INV-1" },
                    { "poNumber", null },
                    { "memo", null },
                    { "lines", new List<object>
                        {
                            new Dictionary<string, object>
                            {
                                { "item", new Dictionary<string, object> { { "listId", "ITEM-1" } } },
                                { "description", "Item" },
                                { "quantity", "2" },
                                { "rate", "10.00" },
                                { "expectedAmount", "20.00" },
                                { "salesTaxCode", new Dictionary<string, object> { { "listId", "TAX-1" } } }
                            }
                        }
                    },
                    { "expectedSubtotal", "20.00" },
                    { "expectedTax", null },
                    { "expectedTotal", null }
                }
            }
        };
        check(ValidateOnlyRequest(validValidationRequest), "validation-only bridge contract accepts the PHP client request shape");
        ((Dictionary<string, object>)validValidationRequest["transaction"])["unexpected"] = "reject";
        check(!ValidateOnlyRequest(validValidationRequest), "validation-only bridge contract rejects unexpected nested fields");
        Process timeoutWorker = null;
        try
        {
            timeoutWorker = Process.Start(new ProcessStartInfo
            {
                FileName = Assembly.GetExecutingAssembly().Location,
                Arguments = "--test-hang-worker",
                UseShellExecute = false,
                CreateNoWindow = true
            });
            check(timeoutWorker != null && !WaitForWorker(timeoutWorker, 100),
                "stalled SDK workers are terminated by the configured timeout");
        }
        finally
        {
            if (timeoutWorker != null) timeoutWorker.Dispose();
        }
        check(!new[] { "CustomerQueryRq", "ItemQueryRq", "SalesTaxCodeQueryRq", "ItemSalesTaxQueryRq", "InventorySiteQueryRq" }
            .Any(name => name.EndsWith("AddRq", StringComparison.Ordinal)),
            "the diagnostic query allowlist contains no transaction-add operation");

        Console.WriteLine(failures == 0 ? "All bridge self-tests passed." : failures + " bridge self-test(s) failed.");
        return failures == 0 ? 0 : 1;
    }

    private sealed class QueryConfig
    {
        public string RequestName;
        public string ResponseName;

        public static QueryConfig For(string entity)
        {
            switch (entity)
            {
                case "customer": return new QueryConfig { RequestName = "CustomerQueryRq", ResponseName = "CustomerQueryRs" };
                case "item": return new QueryConfig { RequestName = "ItemQueryRq", ResponseName = "ItemQueryRs" };
                case "tax_code": return new QueryConfig { RequestName = "SalesTaxCodeQueryRq", ResponseName = "SalesTaxCodeQueryRs" };
                case "tax_item": return new QueryConfig { RequestName = "ItemSalesTaxQueryRq", ResponseName = "ItemSalesTaxQueryRs" };
                case "inventory_site": return new QueryConfig { RequestName = "InventorySiteQueryRq", ResponseName = "InventorySiteQueryRs" };
                default: throw new InvalidDataException("Unsupported diagnostic entity.");
            }
        }
    }

    private sealed class DiagnosticResult
    {
        public int StatusCode;
        public string Status;
        public string Code;
        public Dictionary<string, object> Data;

        public static DiagnosticResult Ready(string entity, List<Dictionary<string, object>> records, string fingerprint, string version)
        {
            return new DiagnosticResult
            {
                StatusCode = 200,
                Status = "ok",
                Data = new Dictionary<string, object>
                {
                    { "entity", entity },
                    { "records", records },
                    { "companyFingerprint", fingerprint },
                    { "qbxmlVersion", version }
                }
            };
        }

        public static DiagnosticResult Unavailable(string code)
        {
            return new DiagnosticResult { StatusCode = 503, Status = "unavailable", Code = code };
        }
    }

    private sealed class ReplayCache
    {
        private readonly string path;

        public ReplayCache(string path)
        {
            this.path = path;
        }

        public bool TryUse(string requestId, long now)
        {
            var directory = Path.GetDirectoryName(path);
            Directory.CreateDirectory(directory);
            var entries = new Dictionary<string, long>(StringComparer.Ordinal);
            if (File.Exists(path))
            {
                foreach (var line in File.ReadAllLines(path, Encoding.UTF8))
                {
                    var parts = line.Split('|');
                    long expiry;
                    if (parts.Length != 2 || !IsGuid(parts[0]) ||
                        !Int64.TryParse(parts[1], NumberStyles.None, CultureInfo.InvariantCulture, out expiry))
                    {
                        throw new InvalidDataException("Replay cache is invalid.");
                    }
                    if (expiry > now) entries[parts[0]] = expiry;
                }
            }
            var normalizedId = Guid.ParseExact(requestId, "D").ToString("D");
            if (entries.ContainsKey(normalizedId))
            {
                return false;
            }
            entries[normalizedId] = now + 60;
            var temporary = path + "." + Guid.NewGuid().ToString("N") + ".tmp";
            File.WriteAllLines(
                temporary,
                entries.Select(entry => entry.Key + "|" + entry.Value.ToString(CultureInfo.InvariantCulture)),
                new UTF8Encoding(false));
            if (File.Exists(path))
            {
                File.Replace(temporary, path, null);
            }
            else
            {
                File.Move(temporary, path);
            }
            return true;
        }
    }

    private sealed class StrictJsonParser
    {
        private readonly string source;
        private int index;
        private static readonly JavaScriptSerializer StringDecoder = new JavaScriptSerializer();

        private StrictJsonParser(string source)
        {
            this.source = source;
        }

        public static object Parse(string source)
        {
            if (source == null || source.Length > MaxRequestBytes)
            {
                throw new FormatException("JSON size is invalid.");
            }
            var parser = new StrictJsonParser(source);
            var result = parser.ReadValue(0);
            parser.SkipWhitespace();
            if (parser.index != source.Length)
            {
                throw new FormatException("Trailing JSON data.");
            }
            return result;
        }

        private object ReadValue(int depth)
        {
            if (depth > 16) throw new FormatException("JSON nesting is too deep.");
            SkipWhitespace();
            if (index >= source.Length) throw new FormatException("Unexpected end of JSON.");
            switch (source[index])
            {
                case '{': return ReadObject(depth + 1);
                case '[': return ReadArray(depth + 1);
                case '"': return ReadString();
                case 't': ReadLiteral("true"); return true;
                case 'f': ReadLiteral("false"); return false;
                case 'n': ReadLiteral("null"); return null;
                default: return ReadNumber();
            }
        }

        private Dictionary<string, object> ReadObject(int depth)
        {
            index++;
            var result = new Dictionary<string, object>(StringComparer.Ordinal);
            SkipWhitespace();
            if (Consume('}')) return result;
            while (true)
            {
                SkipWhitespace();
                if (index >= source.Length || source[index] != '"') throw new FormatException("JSON object key expected.");
                var key = ReadString();
                if (result.ContainsKey(key)) throw new FormatException("Duplicate JSON key.");
                SkipWhitespace();
                Require(':');
                result.Add(key, ReadValue(depth));
                SkipWhitespace();
                if (Consume('}')) return result;
                Require(',');
            }
        }

        private List<object> ReadArray(int depth)
        {
            index++;
            var result = new List<object>();
            SkipWhitespace();
            if (Consume(']')) return result;
            while (true)
            {
                result.Add(ReadValue(depth));
                SkipWhitespace();
                if (Consume(']')) return result;
                Require(',');
            }
        }

        private string ReadString()
        {
            var start = index++;
            bool escaped = false;
            while (index < source.Length)
            {
                var character = source[index++];
                if (escaped)
                {
                    escaped = false;
                }
                else if (character == '\\')
                {
                    escaped = true;
                }
                else if (character == '"')
                {
                    return StringDecoder.Deserialize<string>(source.Substring(start, index - start));
                }
                else if (character < 0x20)
                {
                    throw new FormatException("Unescaped JSON control character.");
                }
            }
            throw new FormatException("Unterminated JSON string.");
        }

        private object ReadNumber()
        {
            var start = index;
            while (index < source.Length && "-+0123456789.eE".IndexOf(source[index]) >= 0) index++;
            if (start == index) throw new FormatException("Invalid JSON value.");
            decimal value;
            var text = source.Substring(start, index - start);
            if (!Decimal.TryParse(text, NumberStyles.Float, CultureInfo.InvariantCulture, out value))
            {
                throw new FormatException("Invalid JSON number.");
            }
            return value;
        }

        private void ReadLiteral(string literal)
        {
            if (index + literal.Length > source.Length
                || !String.Equals(source.Substring(index, literal.Length), literal, StringComparison.Ordinal))
            {
                throw new FormatException("Invalid JSON literal.");
            }
            index += literal.Length;
        }

        private void SkipWhitespace()
        {
            while (index < source.Length && (source[index] == ' ' || source[index] == '\t' || source[index] == '\r' || source[index] == '\n'))
            {
                index++;
            }
        }

        private bool Consume(char value)
        {
            if (index < source.Length && source[index] == value)
            {
                index++;
                return true;
            }
            return false;
        }

        private void Require(char value)
        {
            if (!Consume(value)) throw new FormatException("Invalid JSON structure.");
        }
    }
}
