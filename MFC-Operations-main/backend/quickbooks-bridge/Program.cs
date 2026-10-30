using System;
using System.Collections;
using System.Collections.Generic;
using System.Diagnostics;
using System.Globalization;
using System.IO;
using System.Linq;
using System.Net;
using System.Reflection;
using System.Runtime.InteropServices;
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
    private const string SdkSessionMutexName = @"Local\MFCOperationsQuickBooksSdkSession";
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

        if (args.Length == 1 && args[0] == "--identify-current-company")
        {
            var result = IdentifyCurrentCompany(secret);
            Console.Out.WriteLine(Json.Serialize(result));
            return String.Equals(StringValue(result, "status"), "candidate", StringComparison.Ordinal) ? 0 : 1;
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
                    try
                    {
                        listener.Start();
                    }
                    catch (HttpListenerException error)
                    {
                        Console.Error.WriteLine(
                            "Bridge listener could not bind to 127.0.0.1:8765 (Windows error "
                            + error.ErrorCode.ToString(CultureInfo.InvariantCulture)
                            + "). Check for a port conflict or URL ACL; no process was stopped.");
                        return 2;
                    }
                    Console.WriteLine("QuickBooks bridge listening on 127.0.0.1:8765.");
                    Console.WriteLine("Listener ready; QuickBooks company access has not been verified.");
                    Console.WriteLine("InvoiceAdd remains disabled unless explicitly enabled in the bridge environment.");
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
                || !new[] { "health", "validate", "diagnose", "submit" }.Contains(operation, StringComparer.Ordinal))
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
            if (operation == "submit")
            {
                if (!WriteFeatureEnabled())
                {
                    WriteResponse(context.Response, 503, requestId, "error", "WRITE_DISABLED", secret, new Dictionary<string, object>
                    {
                        { "outcome", "rejected" },
                        { "message", "InvoiceAdd is disabled by the bridge configuration; no request was sent to QuickBooks." }
                    });
                    return;
                }
                if (!ValidateSubmitRequest(request))
                {
                    WriteResponse(context.Response, 400, requestId, "error", "SUBMIT_REQUEST_REJECTED", secret, new Dictionary<string, object>
                    {
                        { "outcome", "rejected" },
                        { "message", "The invoice request failed strict validation; no request was sent to QuickBooks." }
                    });
                    return;
                }
                var invoiceResult = RunInvoiceWrite(request, secret);
                WriteResponse(
                    context.Response,
                    invoiceResult.StatusCode,
                    requestId,
                    invoiceResult.Status,
                    invoiceResult.Code,
                    secret,
                    invoiceResult.Data);
                return;
            }

            string entity;
            List<string> listIds;
            string searchName;
            if (!ValidateDiagnosticRequest(request, out entity, out listIds, out searchName))
            {
                WriteResponse(context.Response, 400, requestId, "error", "DIAGNOSTIC_REQUEST_REJECTED", secret, null);
                return;
            }
            var diagnostic = RunDiagnosticIsolated(entity, listIds, searchName);
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
            { "invoiceWriteEnabled", WriteFeatureEnabled() },
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

    private static bool WriteFeatureEnabled()
    {
        var value = Environment.GetEnvironmentVariable("WQ_QB_INVOICE_WRITE_ENABLED") ?? "";
        return new[] { "1", "true", "yes", "on", "enabled" }.Contains(value.Trim(), StringComparer.OrdinalIgnoreCase);
    }

    private static InvoiceWriteResult RunInvoiceWrite(Dictionary<string, object> request, string secret)
    {
        using (var sdkMutex = new Mutex(false, SdkSessionMutexName))
        {
            bool acquired;
            try { acquired = sdkMutex.WaitOne(0); }
            catch (AbandonedMutexException) { acquired = true; }
            if (!acquired)
            {
                return InvoiceWriteResult.Rejected("QUICKBOOKS_SESSION_BUSY", "The QuickBooks SDK session is busy; no invoice was sent.");
            }
            try
            {
                return RunInvoiceWriteSession(request, secret);
            }
            finally
            {
                sdkMutex.ReleaseMutex();
            }
        }
    }

    private static InvoiceWriteResult RunInvoiceWriteSession(Dictionary<string, object> request, string secret)
    {
        object requestProcessor = null;
        dynamic processor = null;
        string ticket = null;
        bool connectionOpen = false;
        bool sessionOpen = false;
        bool requestMayHaveBeenSent = false;
        try
        {
            var processorType = Type.GetTypeFromProgID(RequestProcessorProgId, true);
            requestProcessor = Activator.CreateInstance(processorType);
            processor = requestProcessor;
            processor.OpenConnection("MFCOperationsInvoiceBridge", "MFC Operations authorized invoice posting");
            connectionOpen = true;
            ticket = (string)processor.BeginSession("", 2);
            sessionOpen = true;

            var companyPath = (string)processor.GetCurrentCompanyFileName(ticket);
            if (String.IsNullOrWhiteSpace(companyPath))
            {
                return InvoiceWriteResult.Rejected("COMPANY_SESSION_UNAVAILABLE", "QuickBooks did not provide an active company session.");
            }
            var version = SelectQbXmlVersion(processor.QBXMLVersionsForSession(ticket) as IEnumerable);
            if (version == null)
            {
                return InvoiceWriteResult.Rejected("QBXML_VERSION_UNSUPPORTED", "The active QuickBooks session does not support a documented qbXML version.");
            }

            var expectedFingerprint = Environment.GetEnvironmentVariable("WQ_QB_COMPANY_FINGERPRINT");
            var identity = QueryCompanyIdentity(processor, ticket, version);
            var fingerprint = CompanyFingerprint(companyPath, identity, secret);
            if (!CompanyFingerprintMatches(fingerprint, expectedFingerprint))
            {
                return InvoiceWriteResult.Rejected("COMPANY_IDENTITY_MISMATCH", "The active QuickBooks company does not match the explicitly configured company.");
            }

            var validationError = VerifyInvoiceReferences(processor, ticket, version, request);
            if (validationError != null)
            {
                return InvoiceWriteResult.Rejected(validationError.Item1, validationError.Item2);
            }

            var currentPath = (string)processor.GetCurrentCompanyFileName(ticket);
            var currentIdentity = QueryCompanyIdentity(processor, ticket, version);
            var currentFingerprint = CompanyFingerprint(currentPath, currentIdentity, secret);
            if (!CompanyFingerprintMatches(currentFingerprint, expectedFingerprint)
                || !String.Equals(fingerprint, currentFingerprint, StringComparison.Ordinal))
            {
                return InvoiceWriteResult.Rejected("COMPANY_IDENTITY_MISMATCH", "The active QuickBooks company identity changed before InvoiceAdd; no invoice was sent.");
            }

            var qbxml = BuildInvoiceAddRequest(request, version);
            requestMayHaveBeenSent = true;
            Func<string, string> sendInvoiceRequest = value => (string)processor.ProcessRequest(ticket, value);
            return SendInvoiceAdd(qbxml, sendInvoiceRequest, currentFingerprint, StringValue(request, "requestId"));
        }
        catch (Exception)
        {
            return requestMayHaveBeenSent
                ? InvoiceWriteResult.Unknown("INVOICE_ADD_OUTCOME_UNKNOWN", "QuickBooks may have received InvoiceAdd, but its result could not be confirmed. Reconcile before any retry.")
                : InvoiceWriteResult.Rejected("INVOICE_PREFLIGHT_FAILED", "QuickBooks preflight could not complete; no InvoiceAdd request was sent.");
        }
        finally
        {
            try
            {
                if (sessionOpen && processor != null) processor.EndSession(ticket);
            }
            catch (Exception) { }
            try
            {
                if (connectionOpen && processor != null) processor.CloseConnection();
            }
            catch (Exception) { }
            if (requestProcessor != null && MarshalIsComObject(requestProcessor))
            {
                try { System.Runtime.InteropServices.Marshal.FinalReleaseComObject(requestProcessor); }
                catch (Exception) { }
            }
        }
    }

    private static InvoiceWriteResult SendInvoiceAdd(
        string qbxml,
        Func<string, string> send,
        string companyFingerprint,
        string requestId)
    {
        string response;
        try
        {
            response = send(qbxml);
        }
        catch (Exception)
        {
            return InvoiceWriteResult.Unknown(
                "INVOICE_ADD_OUTCOME_UNKNOWN",
                "QuickBooks may have received InvoiceAdd, but the SDK did not return a response. Reconcile before any retry.");
        }

        var parsed = ParseInvoiceAddResponse(response, requestId);
        var outcome = StringValue(parsed, "outcome");
        if (outcome == "succeeded")
        {
            return InvoiceWriteResult.Succeeded(new Dictionary<string, object>
            {
                { "outcome", "succeeded" },
                { "txnId", StringValue(parsed, "txnId") },
                { "txnNumber", StringValue(parsed, "txnNumber") },
                { "companyFingerprint", companyFingerprint }
            });
        }
        if (outcome == "rejected")
        {
            return InvoiceWriteResult.Rejected(
                StringValue(parsed, "diagnosticCode"),
                StringValue(parsed, "message"));
        }
        return InvoiceWriteResult.Unknown(
            StringValue(parsed, "diagnosticCode"),
            StringValue(parsed, "message"));
    }

    private static Tuple<string, string> VerifyInvoiceReferences(
        dynamic processor,
        string ticket,
        string version,
        Dictionary<string, object> request)
    {
        var transaction = ObjectValue(request, "transaction");
        var customer = ObjectValue(transaction, "customer");
        var customerId = StringValue(customer, "listId");
        List<Dictionary<string, object>> customerRecords =
            QueryRecords(processor, ticket, "customer", new List<string> { customerId }, version);
        var customerRecord = customerRecords.Single();
        if (!IsActiveRecord(customerRecord))
        {
            return Tuple.Create("CUSTOMER_REFERENCE_INVALID", "The mapped QuickBooks customer is missing or inactive.");
        }

        var lines = ArrayValue(transaction, "lines");
        var itemIds = lines.Select(line => StringValue(ObjectValue((Dictionary<string, object>)line, "item"), "listId"))
            .Distinct(StringComparer.Ordinal)
            .ToList();
        foreach (var itemId in itemIds)
        {
            List<Dictionary<string, object>> itemRecords =
                QueryRecords(processor, ticket, "item", new List<string> { itemId }, version);
            var item = itemRecords.Single();
            if (!IsActiveRecord(item))
            {
                return Tuple.Create("ITEM_REFERENCE_INVALID", "A mapped QuickBooks item is missing or inactive.");
            }
        }

        var taxIds = lines.Select(line => StringValue(ObjectValue((Dictionary<string, object>)line, "salesTaxCode"), "listId"))
            .Distinct(StringComparer.Ordinal)
            .ToList();
        foreach (var taxId in taxIds)
        {
            List<Dictionary<string, object>> taxRecords =
                QueryRecords(processor, ticket, "tax_code", new List<string> { taxId }, version);
            var tax = taxRecords.Single();
            if (!IsActiveRecord(tax))
            {
                return Tuple.Create("TAX_CODE_INVALID", "A mapped QuickBooks sales-tax code is missing or inactive.");
            }
        }

        List<Dictionary<string, object>> preferenceRecords =
            QueryCompanyPreferences(processor, ticket, version);
        var preferences = preferenceRecords.Single();
        if (!BooleanValue(preferences, "customerTaxCodeEnabled"))
        {
            return Tuple.Create("CUSTOMER_TAX_CODES_DISABLED", "The active QuickBooks company does not have customer sales-tax codes enabled.");
        }
        if (!BooleanValue(preferences, "multiLocationAvailable")
            || !BooleanValue(preferences, "multiLocationEnabled"))
        {
            return Tuple.Create("INVENTORY_SITES_DISABLED", "The active QuickBooks company does not have inventory-site tracking enabled.");
        }

        foreach (var value in lines)
        {
            var line = (Dictionary<string, object>)value;
            var siteName = StringValue(line, "site");
            var sites = QueryRecordsByName(processor, ticket, "inventory_site", siteName, version);
            if (sites.Count != 1
                || !IsActiveRecord(sites[0])
                || !String.Equals(StringValue(sites[0], "fullName"), siteName, StringComparison.Ordinal))
            {
                return Tuple.Create("SITE_REFERENCE_INVALID", "Each invoice line must resolve to exactly one active QuickBooks site with the exact configured FullName.");
            }

            var unitName = StringValue(line, "unitOfMeasure");
            if (!String.IsNullOrWhiteSpace(unitName))
            {
                var itemId = StringValue(ObjectValue(line, "item"), "listId");
                List<Dictionary<string, object>> unitRecords =
                    QueryItemUnits(processor, ticket, new List<string> { itemId }, version);
                var units = unitRecords.Single();
                var unitNames = ((List<object>)units["units"]).OfType<Dictionary<string, object>>()
                    .Select(unit => StringValue(unit, "name"));
                if (!IsActiveRecord(units)
                    || !Equals(units["unitSetActive"], true)
                    || !String.Equals(StringValue(units, "salesDefaultUnit"), unitName, StringComparison.Ordinal)
                    || !unitNames.Contains(unitName, StringComparer.Ordinal))
                {
                    return Tuple.Create("UNIT_OF_MEASURE_INVALID", "The supplied unit does not match the active item's QuickBooks sales default unit.");
                }
            }
        }

        var hasLots = lines.Any(line => !String.IsNullOrWhiteSpace(StringValue((Dictionary<string, object>)line, "lotNumber")));
        if (hasLots && !String.Equals(StringValue(preferences, "lotTracking"), "LotNumber", StringComparison.Ordinal))
        {
            return Tuple.Create("LOT_TRACKING_UNAVAILABLE", "The active QuickBooks company is not configured for lot-number tracking.");
        }
        return null;
    }

    private static bool IsActiveRecord(Dictionary<string, object> record)
    {
        return record.ContainsKey("found")
            && BooleanValue(record, "found")
            && record.ContainsKey("active")
            && BooleanValue(record, "active");
    }

    private static bool ValidateSubmitRequest(Dictionary<string, object> request)
    {
        if (!OnlyKeys(request, "protocolVersion", "requestId", "operation", "transactionType", "idempotencyKey", "source", "transaction")
            || !HasKeys(request, "protocolVersion", "requestId", "operation", "transactionType", "idempotencyKey", "source", "transaction")
            || !String.Equals(StringValue(request, "protocolVersion"), "1", StringComparison.Ordinal)
            || !String.Equals(StringValue(request, "operation"), "submit", StringComparison.Ordinal)
            || !String.Equals(StringValue(request, "transactionType"), "invoice", StringComparison.Ordinal)
            || !IsGuid(StringValue(request, "requestId"))
            || !Regex.IsMatch(StringValue(request, "idempotencyKey") ?? "", "^[a-f0-9]{64}$"))
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
            || !Regex.IsMatch(StringValue(source, "revision") ?? "", "^[a-f0-9]{64}$"))
        {
            return false;
        }

        if (!OnlyKeys(transaction, "customer", "txnDate", "refNumber", "poNumber", "memo", "terms", "class",
                "billAddress", "shipAddress", "lines", "expectedSubtotal", "expectedTax", "expectedTotal")
            || !HasKeys(transaction, "customer", "txnDate", "refNumber", "poNumber", "memo", "lines",
                "expectedSubtotal", "expectedTax", "expectedTotal")
            || !IsValidDate(StringValue(transaction, "txnDate"))
            || !IsBoundedString(StringValue(transaction, "refNumber"), 11, false)
            || !IsBoundedStringValue(transaction, "poNumber", 25)
            || !IsBoundedStringValue(transaction, "memo", 4095)
            || !IsMoneyString(StringValue(transaction, "expectedSubtotal"))
            || !IsNullValue(transaction, "expectedTax")
            || !IsNullValue(transaction, "expectedTotal"))
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
            var item = line == null ? null : ObjectValue(line, "item");
            var taxCode = line == null ? null : ObjectValue(line, "salesTaxCode");
            if (line == null
                || !OnlyKeys(line, "item", "description", "quantity", "rate", "expectedAmount", "salesTaxCode",
                    "site", "lotNumber", "expirationDate", "unitOfMeasure")
                || !HasKeys(line, "item", "description", "quantity", "rate", "expectedAmount", "salesTaxCode")
                || item == null || !OnlyKeys(item, "listId") || !HasKeys(item, "listId")
                || !IsValidListId(StringValue(item, "listId"))
                || !IsBoundedString(StringValue(line, "description"), 4095, false)
                || !IsPositiveQuantity(StringValue(line, "quantity"))
                || !IsMoneyString(StringValue(line, "rate"))
                || !IsMoneyString(StringValue(line, "expectedAmount"))
                || taxCode == null || !OnlyKeys(taxCode, "listId") || !HasKeys(taxCode, "listId")
                || !IsValidListId(StringValue(taxCode, "listId"))
                || !IsBoundedStringValue(line, "site", 31)
                || String.IsNullOrWhiteSpace(StringValue(line, "site"))
                || !IsBoundedStringValue(line, "lotNumber", 40)
                || !IsBoundedStringValue(line, "unitOfMeasure", 31)
                || !IsNullOrEmptyIfPresent(line, "expirationDate"))
            {
                return false;
            }
            decimal amount;
            decimal rate;
            decimal quantity;
            if (!Decimal.TryParse(StringValue(line, "expectedAmount"), NumberStyles.AllowDecimalPoint, CultureInfo.InvariantCulture, out amount)
                || !Decimal.TryParse(StringValue(line, "rate"), NumberStyles.AllowDecimalPoint, CultureInfo.InvariantCulture, out rate)
                || !Decimal.TryParse(StringValue(line, "quantity"), NumberStyles.None, CultureInfo.InvariantCulture, out quantity)
                || amount != Decimal.Round(rate * quantity, 2, MidpointRounding.AwayFromZero))
            {
                return false;
            }
            subtotal += amount;
        }

        var expectedSubtotal = StringValue(transaction, "expectedSubtotal");
        decimal expectedTotalValue;
        if (!Decimal.TryParse(expectedSubtotal, NumberStyles.AllowDecimalPoint, CultureInfo.InvariantCulture, out expectedTotalValue)
            || subtotal != expectedTotalValue)
        {
            return false;
        }

        return true;
    }

    private static string BuildInvoiceAddRequest(Dictionary<string, object> request, string qbxmlVersion)
    {
        if (!ValidateSubmitRequest(request) || !IsBoundedString(qbxmlVersion, 20, false))
        {
            throw new InvalidDataException("The invoice request or negotiated qbXML version is invalid.");
        }

        var transaction = ObjectValue(request, "transaction");
        var customer = ObjectValue(transaction, "customer");
        var lines = ArrayValue(transaction, "lines");
        var builder = new StringBuilder();
        var settings = new XmlWriterSettings { OmitXmlDeclaration = true, Encoding = new UTF8Encoding(false), Indent = false };
        builder.Append("<?xml version=\"1.0\" encoding=\"utf-8\"?><?qbxml version=\"")
            .Append(qbxmlVersion)
            .Append("\"?>");
        using (var writer = XmlWriter.Create(builder, settings))
        {
            writer.WriteStartElement("QBXML");
            writer.WriteStartElement("QBXMLMsgsRq");
            writer.WriteAttributeString("onError", "stopOnError");
            writer.WriteStartElement("InvoiceAddRq");
            writer.WriteAttributeString("requestID", StringValue(request, "requestId"));
            writer.WriteStartElement("InvoiceAdd");
            WriteListReference(writer, "CustomerRef", StringValue(customer, "listId"));
            writer.WriteElementString("TxnDate", StringValue(transaction, "txnDate"));
            writer.WriteElementString("RefNumber", StringValue(transaction, "refNumber"));
            WriteOptionalElement(writer, "PONumber", transaction, "poNumber");
            WriteOptionalElement(writer, "Memo", transaction, "memo");
            foreach (var value in lines)
            {
                var line = value as Dictionary<string, object>;
                var item = ObjectValue(line, "item");
                var taxCode = ObjectValue(line, "salesTaxCode");
                writer.WriteStartElement("InvoiceLineAdd");
                WriteListReference(writer, "ItemRef", StringValue(item, "listId"));
                writer.WriteElementString("Desc", StringValue(line, "description"));
                writer.WriteElementString("Quantity", StringValue(line, "quantity"));
                WriteOptionalElement(writer, "UnitOfMeasure", line, "unitOfMeasure");
                writer.WriteElementString("Rate", StringValue(line, "rate"));
                writer.WriteElementString("Amount", StringValue(line, "expectedAmount"));
                WriteListReference(writer, "InventorySiteRef", null, StringValue(line, "site"));
                WriteOptionalElement(writer, "LotNumber", line, "lotNumber");
                WriteListReference(writer, "SalesTaxCodeRef", StringValue(taxCode, "listId"));
                writer.WriteEndElement();
            }
            writer.WriteEndElement();
            writer.WriteElementString("IncludeRetElement", "TxnID");
            writer.WriteElementString("IncludeRetElement", "TxnNumber");
            writer.WriteEndElement();
            writer.WriteEndElement();
        }
        return builder.ToString();
    }

    private static Dictionary<string, object> ParseInvoiceAddResponse(string qbxml, string expectedRequestId)
    {
        try
        {
            var document = LoadSafeXml(qbxml);
            var response = document.SelectSingleNode("/*[local-name()='QBXML']/*[local-name()='QBXMLMsgsRs']/*[local-name()='InvoiceAddRs']");
            var attributes = response == null ? null : response.Attributes;
            int statusCode;
            if (attributes == null
                || !IsGuid(expectedRequestId)
                || attributes["requestID"] == null
                || !String.Equals(attributes["requestID"].Value, expectedRequestId, StringComparison.Ordinal)
                || !Int32.TryParse(attributes["statusCode"] == null ? null : attributes["statusCode"].Value, NumberStyles.None, CultureInfo.InvariantCulture, out statusCode))
            {
                return InvoiceOutcome("unknown", "INVOICE_ADD_RESPONSE_INVALID", "QuickBooks did not return a valid InvoiceAdd response.");
            }

            var severity = attributes["statusSeverity"] == null ? "" : attributes["statusSeverity"].Value;
            var message = attributes["statusMessage"] == null ? "" : attributes["statusMessage"].Value;
            if (statusCode != 0)
            {
                return InvoiceOutcome(
                    "rejected",
                    "QUICKBOOKS_REJECTED",
                    SanitizeQuickBooksMessage(statusCode, severity, message));
            }

            var invoice = response.SelectSingleNode("./*[local-name()='InvoiceRet']");
            var txnId = invoice == null ? null : ChildText(invoice, "TxnID");
            var txnNumber = invoice == null ? null : ChildText(invoice, "TxnNumber");
            if (!IsValidListId(txnId)
                || String.IsNullOrWhiteSpace(txnNumber)
                || !Regex.IsMatch(txnNumber, "^\\d{1,10}$"))
            {
                return InvoiceOutcome("unknown", "INVOICE_ADD_RESPONSE_INVALID", "QuickBooks returned success without a valid transaction identifier.");
            }

            var result = InvoiceOutcome("succeeded", null, null);
            result["txnId"] = txnId;
            result["txnNumber"] = txnNumber;
            return result;
        }
        catch (Exception)
        {
            return InvoiceOutcome("unknown", "INVOICE_ADD_RESPONSE_INVALID", "QuickBooks returned a malformed or incomplete InvoiceAdd response.");
        }
    }

    private static Dictionary<string, object> InvoiceOutcome(string outcome, string code, string message)
    {
        return new Dictionary<string, object>
        {
            { "outcome", outcome },
            { "diagnosticCode", code },
            { "message", message }
        };
    }

    private static string SanitizeQuickBooksMessage(int statusCode, string severity, string message)
    {
        var safe = Regex.Replace(message ?? "", "[\\x00-\\x1F\\x7F]", " ").Trim();
        safe = Regex.Replace(safe, "<[^>]*>", " ");
        safe = Regex.Replace(safe, "\\s+", " ");
        if (safe.Length > 300)
        {
            safe = safe.Substring(0, 300);
        }
        return "QuickBooks rejected the invoice (status " + statusCode.ToString(CultureInfo.InvariantCulture)
            + (String.IsNullOrWhiteSpace(severity) ? "" : ", " + Regex.Replace(severity, "[^A-Za-z]", ""))
            + ")." + (safe.Length == 0 ? "" : " " + safe);
    }

    private static void WriteListReference(XmlWriter writer, string element, string listId, string fullName = null)
    {
        writer.WriteStartElement(element);
        if (!String.IsNullOrWhiteSpace(listId))
        {
            writer.WriteElementString("ListID", listId);
        }
        else if (!String.IsNullOrWhiteSpace(fullName))
        {
            writer.WriteElementString("FullName", fullName);
        }
        writer.WriteEndElement();
    }

    private static void WriteOptionalElement(XmlWriter writer, string element, Dictionary<string, object> source, string key)
    {
        var value = StringValue(source, key);
        if (!String.IsNullOrEmpty(value))
        {
            writer.WriteElementString(element, value);
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
        out List<string> listIds,
        out string searchName)
    {
        entity = StringValue(request, "entity");
        listIds = null;
        searchName = StringValue(request, "searchName");
        if (!OnlyKeys(request, "operation", "requestId", "entity", "listIds", "searchName")
            || !String.Equals(StringValue(request, "operation"), "diagnose", StringComparison.Ordinal)
            || !new[]
            {
                "customer", "item", "tax_code", "tax_item", "inventory_site",
                "item_uom", "invoice_custom_fields", "company_preferences"
            }.Contains(entity, StringComparer.Ordinal)
            || (request.ContainsKey("searchName") && request.ContainsKey("listIds")))
        {
            return false;
        }
        if (request.ContainsKey("searchName"))
        {
            return (entity == "customer" || entity == "item" || entity == "inventory_site")
                && IsBoundedString(searchName, 255, false)
                && String.Equals(searchName, searchName.Trim(), StringComparison.Ordinal)
                && !searchName.Any(Char.IsControl);
        }
        if (entity == "invoice_custom_fields" || entity == "company_preferences")
        {
            return !request.ContainsKey("listIds");
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

    private static DiagnosticResult RunDiagnostic(string entity, List<string> listIds, string searchName, string secret)
    {
        using (var sdkMutex = new Mutex(false, SdkSessionMutexName))
        {
            bool acquired;
            try { acquired = sdkMutex.WaitOne(0); }
            catch (AbandonedMutexException) { acquired = true; }
            if (!acquired)
            {
                return DiagnosticResult.Unavailable("QUICKBOOKS_SESSION_BUSY");
            }
            try
            {
                return RunDiagnosticSession(entity, listIds, searchName, secret);
            }
            finally
            {
                sdkMutex.ReleaseMutex();
            }
        }
    }

    private static DiagnosticResult RunDiagnosticSession(string entity, List<string> listIds, string searchName, string secret)
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

            failureCode = "COMPANY_IDENTITY_UNAVAILABLE";
            var companyIdentity = QueryCompanyIdentity(processor, ticket, version);
            var companyFingerprint = CompanyFingerprint(companyPath, companyIdentity, secret);
            var expectedFingerprint = Environment.GetEnvironmentVariable("WQ_QB_COMPANY_FINGERPRINT");
            var fingerprintConfigurationError = CompanyFingerprintConfigurationError(expectedFingerprint);
            if (fingerprintConfigurationError != null)
            {
                return DiagnosticResult.Unavailable(fingerprintConfigurationError);
            }
            if (!CompanyFingerprintMatches(companyFingerprint, expectedFingerprint))
            {
                return DiagnosticResult.Unavailable("COMPANY_IDENTITY_MISMATCH");
            }

            failureCode = "QBXML_QUERY_FAILED";
            List<Dictionary<string, object>> records;
            if (entity == "company_preferences")
            {
                records = QueryCompanyPreferences(processor, ticket, version);
            }
            else if (entity == "invoice_custom_fields")
            {
                records = QueryInvoiceCustomFields(processor, ticket, version);
            }
            else if (entity == "item_uom")
            {
                records = QueryItemUnits(processor, ticket, listIds, version);
            }
            else if (searchName != null)
            {
                records = QueryRecordsByName(processor, ticket, entity, searchName, version);
            }
            else
            {
                records = QueryRecords(processor, ticket, entity, listIds, version);
            }
            return DiagnosticResult.Ready(entity, records, companyFingerprint, companyIdentity, version, searchName);
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

    private static Dictionary<string, object> IdentifyCurrentCompany(string secret)
    {
        using (var sdkMutex = new Mutex(false, SdkSessionMutexName))
        {
            bool acquired;
            try { acquired = sdkMutex.WaitOne(0); }
            catch (AbandonedMutexException) { acquired = true; }
            if (!acquired)
            {
                return new Dictionary<string, object>
                {
                    { "status", "unavailable" },
                    { "diagnosticCode", "QUICKBOOKS_SESSION_BUSY" },
                    { "message", "Another QuickBooks SDK operation is active." }
                };
            }
            try
            {
                return IdentifyCurrentCompanySession(secret);
            }
            finally
            {
                sdkMutex.ReleaseMutex();
            }
        }
    }

    private static Dictionary<string, object> IdentifyCurrentCompanySession(string secret)
    {
        object requestProcessor = null;
        dynamic processor = null;
        string ticket = null;
        bool connectionOpen = false;
        bool sessionOpen = false;
        string stage = "SDK_ACTIVATION";
        Dictionary<string, object> result = null;
        Exception cleanupFailure = null;
        try
        {
            var processorType = Type.GetTypeFromProgID(RequestProcessorProgId, true);
            requestProcessor = Activator.CreateInstance(processorType);
            processor = requestProcessor;
            stage = "SDK_OPEN_CONNECTION";
            processor.OpenConnection("MFCOperationsReadOnlyBridge", "MFC Operations read-only company identity check");
            connectionOpen = true;
            stage = "SDK_BEGIN_SESSION";
            ticket = (string)processor.BeginSession("", 2);
            sessionOpen = true;
            stage = "SDK_COMPANY_FILE_NAME";
            var companyPath = (string)processor.GetCurrentCompanyFileName(ticket);
            if (String.IsNullOrWhiteSpace(companyPath))
            {
                throw new InvalidDataException();
            }
            stage = "SDK_QBXML_CAPABILITIES";
            var supported = processor.QBXMLVersionsForSession(ticket) as IEnumerable;
            var version = SelectQbXmlVersion(supported);
            if (version == null)
            {
                throw new InvalidDataException();
            }
            stage = "COMPANY_QUERY";
            var companyIdentity = QueryCompanyIdentity(processor, ticket, version);
            result = new Dictionary<string, object>
            {
                { "status", "candidate" },
                { "companyFingerprint", CompanyFingerprint(companyPath, companyIdentity, secret) },
                { "companyIdentity", companyIdentity },
                { "message", "Candidate identity only; independently confirm the open company before configuring it as expected." }
            };
        }
        catch (Exception error)
        {
            result = CompanyIdentityFailure(error, stage);
        }
        finally
        {
            if (sessionOpen && processor != null)
            {
                try
                {
                    processor.EndSession(ticket);
                }
                catch (Exception error)
                {
                    cleanupFailure = error;
                }
            }
            if (connectionOpen && processor != null)
            {
                try
                {
                    processor.CloseConnection();
                }
                catch (Exception error)
                {
                    if (cleanupFailure == null) cleanupFailure = error;
                }
            }
            if (requestProcessor != null && MarshalIsComObject(requestProcessor))
            {
                try { System.Runtime.InteropServices.Marshal.FinalReleaseComObject(requestProcessor); }
                catch (Exception error)
                {
                    if (cleanupFailure == null) cleanupFailure = error;
                }
            }
        }
        return cleanupFailure == null || StringValue(result, "status") != "candidate"
            ? result
            : CompanyIdentityFailure(cleanupFailure, "SDK_SESSION_CLEANUP");
    }

    private static Dictionary<string, object> QueryCompanyIdentity(dynamic processor, string ticket, string version)
    {
        var document = LoadSafeXml((string)processor.ProcessRequest(
            ticket,
            BuildQueryXml(
                version,
                "CompanyQueryRq",
                null,
                null,
                new[] { "CompanyName", "LegalCompanyName" })));
        var response = document.SelectSingleNode("//*[local-name()='CompanyQueryRs']");
        if (response == null)
        {
            throw new CompanyIdentityResponseException("COMPANY_QUERY_RESPONSE_MISSING");
        }
        var statusCode = response.Attributes["statusCode"] == null
            ? null
            : response.Attributes["statusCode"].Value;
        if (!String.Equals(statusCode, "0", StringComparison.Ordinal))
        {
            var severity = response.Attributes["statusSeverity"] == null
                ? null
                : response.Attributes["statusSeverity"].Value;
            throw new CompanyQueryStatusException(statusCode, severity);
        }
        var company = response.SelectSingleNode("./*[local-name()='CompanyRet']");
        var companyName = company == null ? null : ChildText(company, "CompanyName");
        var legalCompanyName = company == null ? null : ChildText(company, "LegalCompanyName");
        if (!IsBoundedString(companyName, 59, false)
            || !IsBoundedString(legalCompanyName, 59, false))
        {
            throw new CompanyIdentityResponseException("COMPANY_IDENTITY_FIELDS_MISSING");
        }
        return new Dictionary<string, object>
        {
            { "companyName", companyName.Trim() },
            { "legalCompanyName", legalCompanyName.Trim() }
        };
    }

    private static Dictionary<string, object> CompanyIdentityFailure(Exception error, string stage)
    {
        var queryStatus = error as CompanyQueryStatusException;
        if (queryStatus != null)
        {
            var validStatus = Regex.IsMatch(queryStatus.StatusCode ?? "", "^[0-9]{1,5}$")
                ? queryStatus.StatusCode
                : "unknown";
            var severity = Regex.IsMatch(queryStatus.Severity ?? "", "^[A-Za-z]{1,20}$")
                ? queryStatus.Severity
                : "unknown";
            return new Dictionary<string, object>
            {
                { "status", "unavailable" },
                { "diagnosticCode", "COMPANY_QUERY_QBXML_STATUS_" + validStatus },
                { "message", "QuickBooks rejected CompanyQueryRq (statusCode " + validStatus
                    + ", severity " + severity + "). Confirm SDK authorization and review the QuickBooks SDK log." }
            };
        }

        var responseFailure = error as CompanyIdentityResponseException;
        if (responseFailure != null)
        {
            return new Dictionary<string, object>
            {
                { "status", "unavailable" },
                { "diagnosticCode", responseFailure.DiagnosticCode },
                { "message", "The read-only CompanyQueryRq response was missing required identity data or was malformed. Confirm QuickBooks and SDK versions, then retry." }
            };
        }

        var comError = FindComException(error);
        if (comError != null)
        {
            var hresult = unchecked((uint)comError.ErrorCode).ToString("X8", CultureInfo.InvariantCulture);
            var safeStage = Regex.IsMatch(stage ?? "", "^[A-Z0-9_]{1,50}$") ? stage : "SDK_OPERATION";
            return new Dictionary<string, object>
            {
                { "status", "unavailable" },
                { "diagnosticCode", "SDK_COM_" + hresult },
                { "message", "The QuickBooks SDK failed during " + safeStage + " (HRESULT 0x" + hresult
                    + "). Confirm the intended company is open, complete any normal QuickBooks SDK authorization prompt, and review the QuickBooks SDK log." }
            };
        }

        var xmlError = FindException<XmlException>(error);
        if (xmlError != null)
        {
            return new Dictionary<string, object>
            {
                { "status", "unavailable" },
                { "diagnosticCode", "COMPANY_QUERY_XML_INVALID" },
                { "message", "QuickBooks returned malformed XML for the read-only company identity query. Confirm the installed SDK and QuickBooks versions." }
            };
        }

        var safeFailureCode = stage == "SDK_ACTIVATION"
            ? "SDK_ACTIVATION_FAILED"
            : stage == "SDK_SESSION_CLEANUP"
                ? "SDK_SESSION_CLEANUP_FAILED"
                : stage == "SDK_COMPANY_FILE_NAME"
                    ? "COMPANY_FILE_IDENTITY_UNAVAILABLE"
                    : stage == "SDK_QBXML_CAPABILITIES"
                        ? "QBXML_VERSION_UNAVAILABLE"
                        : "COMPANY_IDENTITY_UNAVAILABLE";
        var safeMessage = stage == "SDK_SESSION_CLEANUP"
            ? "The QuickBooks SDK session could not be closed cleanly. Restart QuickBooks and the bridge before retrying."
            : stage == "SDK_ACTIVATION"
                ? "The QuickBooks RequestProcessor could not be created. Verify SDK registration and matching 32/64-bit configuration."
                : stage == "SDK_OPEN_CONNECTION"
                    ? "The QuickBooks SDK connection could not be opened. Confirm the SDK installation and QuickBooks authorization."
                    : stage == "SDK_BEGIN_SESSION"
                        ? "The QuickBooks SDK session could not be started. Confirm the intended company is open and complete any normal authorization prompt."
                        : stage == "SDK_COMPANY_FILE_NAME"
                            ? "QuickBooks did not provide the active company-file identity. Confirm a company file is open in QuickBooks."
                            : stage == "SDK_QBXML_CAPABILITIES"
                                ? "QuickBooks did not report a supported qbXML version for this SDK session."
                                : "The current QuickBooks company identity could not be read.";
        return new Dictionary<string, object>
        {
            { "status", "unavailable" },
            { "diagnosticCode", safeFailureCode },
            { "message", safeMessage }
        };
    }

    private static COMException FindComException(Exception error)
    {
        for (var current = error; current != null; current = current.InnerException)
        {
            var comError = current as COMException;
            if (comError != null) return comError;
        }
        return null;
    }

    private static TException FindException<TException>(Exception error) where TException : Exception
    {
        for (var current = error; current != null; current = current.InnerException)
        {
            var match = current as TException;
            if (match != null) return match;
        }
        return null;
    }

    private sealed class CompanyIdentityResponseException : Exception
    {
        public readonly string DiagnosticCode;

        public CompanyIdentityResponseException(string diagnosticCode)
        {
            DiagnosticCode = diagnosticCode;
        }
    }

    private sealed class CompanyQueryStatusException : Exception
    {
        public readonly string StatusCode;
        public readonly string Severity;

        public CompanyQueryStatusException(string statusCode, string severity)
        {
            StatusCode = statusCode;
            Severity = severity;
        }
    }

    private static string CompanyFingerprint(
        string companyPath,
        Dictionary<string, object> companyIdentity,
        string secret)
    {
        var canonicalPath = Path.GetFullPath(companyPath).Normalize(NormalizationForm.FormC).ToUpperInvariant();
        var companyName = StringValue(companyIdentity, "companyName")
            .Normalize(NormalizationForm.FormC).Trim().ToUpperInvariant();
        var legalCompanyName = StringValue(companyIdentity, "legalCompanyName")
            .Normalize(NormalizationForm.FormC).Trim().ToUpperInvariant();
        return HmacHex(secret, Encoding.UTF8.GetBytes(
            "company-identity-v2\n" + canonicalPath + "\n" + companyName + "\n" + legalCompanyName));
    }

    private static string CompanyFingerprintConfigurationError(string expectedFingerprint)
    {
        if (String.IsNullOrWhiteSpace(expectedFingerprint))
        {
            return "COMPANY_FINGERPRINT_NOT_CONFIGURED";
        }
        return Regex.IsMatch(expectedFingerprint, "^[a-fA-F0-9]{64}$")
            ? null
            : "COMPANY_FINGERPRINT_INVALID";
    }

    private static bool CompanyFingerprintMatches(string actualFingerprint, string expectedFingerprint)
    {
        return CompanyFingerprintConfigurationError(expectedFingerprint) == null
            && ConstantTimeEquals(actualFingerprint, expectedFingerprint.ToLowerInvariant());
    }

    private static DiagnosticResult RunDiagnosticIsolated(string entity, List<string> listIds, string searchName)
    {
        var expectedFingerprint = Environment.GetEnvironmentVariable("WQ_QB_COMPANY_FINGERPRINT");
        var fingerprintConfigurationError = CompanyFingerprintConfigurationError(expectedFingerprint);
        if (fingerprintConfigurationError != null)
        {
            return DiagnosticResult.Unavailable(fingerprintConfigurationError);
        }
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
                { "listIds", listIds ?? new List<string>() },
                { "searchName", searchName }
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
            if (request == null || !OnlyKeys(request, "entity", "listIds", "searchName"))
            {
                throw new FormatException("Invalid worker request.");
            }
            var entity = StringValue(request, "entity");
            var values = ArrayValue(request, "listIds");
            var searchName = StringValue(request, "searchName");
            if (String.IsNullOrEmpty(entity) || values == null
                || (searchName == null && (values.Count < 1 || values.Count > MaxListIds))
                || (searchName != null && (values.Count != 0
                    || !IsBoundedString(searchName, 255, false)
                    || !String.Equals(searchName, searchName.Trim(), StringComparison.Ordinal))))
            {
                throw new FormatException("Invalid worker request.");
            }
            var listIds = values.Select(value => value as string).ToList();
            if (listIds.Any(value => String.IsNullOrEmpty(value)))
            {
                throw new FormatException("Invalid worker request.");
            }
            var secret = Environment.GetEnvironmentVariable("WQ_QB_BRIDGE_SECRET") ?? "";
            var result = RunDiagnostic(entity, listIds, searchName, secret);
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
                records[listId] = new Dictionary<string, object>
                {
                    { "listId", returnedId },
                    { "name", name },
                    { "active", ParseActiveStatus(activeText) },
                    { "found", true }
                };
                if (entity == "item")
                {
                    records[listId]["manufacturerPartNumber"] = ChildText(node, "ManufacturerPartNumber");
                }
            }
        }
        return listIds.Select(id => records.ContainsKey(id)
            ? records[id]
            : new Dictionary<string, object> { { "listId", id }, { "found", false } }).ToList();
    }

    private static List<Dictionary<string, object>> QueryItemUnits(
        dynamic processor,
        string ticket,
        List<string> listIds,
        string version)
    {
        var records = new List<Dictionary<string, object>>();
        foreach (var listId in listIds)
        {
            XmlNode itemResponse = RunQuery(
                processor,
                ticket,
                version,
                BuildQueryXml(
                    version,
                    "ItemQueryRq",
                    "ListID",
                    listId,
                    new[] { "ListID", "Name", "FullName", "IsActive", "UnitOfMeasureSetRef" }),
                "ItemQueryRs");
            XmlNode itemNode = null;
            foreach (XmlNode child in itemResponse.ChildNodes)
            {
                if (child.NodeType == XmlNodeType.Element
                    && child.LocalName.EndsWith("Ret", StringComparison.Ordinal)
                    && String.Equals(ChildText(child, "ListID"), listId, StringComparison.Ordinal))
                {
                    itemNode = child;
                    break;
                }
            }

            if (itemNode == null)
            {
                records.Add(new Dictionary<string, object>
                {
                    { "listId", listId }, { "found", false }, { "itemName", null },
                    { "active", null }, { "unitSetListId", null }, { "unitSetName", null },
                    { "unitSetActive", null }, { "unitType", null }, { "units", new List<object>() },
                    { "salesDefaultUnit", null }
                });
                continue;
            }

            var unitSet = itemNode.SelectSingleNode("./*[local-name()='UnitOfMeasureSetRef']");
            var unitSetListId = unitSet == null ? null : ChildText(unitSet, "ListID");
            var unitSetName = unitSet == null ? null : ChildText(unitSet, "FullName");
            var unitNames = new List<object>();
            string unitType = null;
            string salesDefault = null;
            object unitSetActive = null;
            if (IsValidListId(unitSetListId))
            {
                XmlNode unitResponse = RunQuery(
                    processor,
                    ticket,
                    version,
                    BuildUnrestrictedListQuery(version, "UnitOfMeasureSetQueryRq", "ListID", unitSetListId),
                    "UnitOfMeasureSetQueryRs");
                XmlNode unitNode = unitResponse.ChildNodes.Cast<XmlNode>().FirstOrDefault(node =>
                    node.NodeType == XmlNodeType.Element
                    && node.LocalName == "UnitOfMeasureSetRet"
                    && String.Equals(ChildText(node, "ListID"), unitSetListId, StringComparison.Ordinal));
                if (unitNode != null)
                {
                    unitSetActive = ParseActiveStatus(ChildText(unitNode, "IsActive"));
                    unitType = ChildText(unitNode, "UnitOfMeasureType");
                    foreach (XmlNode unit in unitNode.SelectNodes("./*[local-name()='BaseUnit'] | ./*[local-name()='RelatedUnit']"))
                    {
                        var unitName = ChildText(unit, "Name");
                        if (!String.IsNullOrWhiteSpace(unitName))
                        {
                            unitNames.Add(new Dictionary<string, object>
                            {
                                { "name", unitName },
                                { "abbreviation", ChildText(unit, "Abbreviation") }
                            });
                        }
                    }
                    foreach (XmlNode defaultUnit in unitNode.SelectNodes("./*[local-name()='DefaultUnit']"))
                    {
                        if (String.Equals(ChildText(defaultUnit, "UnitUsedFor"), "Sales", StringComparison.Ordinal))
                        {
                            salesDefault = ChildText(defaultUnit, "Unit");
                        }
                    }
                }
            }
            records.Add(new Dictionary<string, object>
            {
                { "listId", listId },
                { "found", true },
                { "itemName", ChildText(itemNode, "FullName") ?? ChildText(itemNode, "Name") },
                { "active", ParseActiveStatus(ChildText(itemNode, "IsActive")) },
                { "unitSetListId", unitSetListId },
                { "unitSetName", unitSetName },
                { "unitSetActive", unitSetActive },
                { "unitType", unitType },
                { "units", unitNames },
                { "salesDefaultUnit", salesDefault }
            });
        }
        return records;
    }

    private static List<Dictionary<string, object>> QueryCompanyPreferences(dynamic processor, string ticket, string version)
    {
        XmlNode response = RunQuery(
            processor,
            ticket,
            version,
            BuildEmptyQuery(version, "PreferencesQueryRq"),
            "PreferencesQueryRs");
        XmlNode preferences = response.SelectSingleNode("./*[local-name()='PreferencesRet']");
        if (preferences == null)
        {
            throw new InvalidDataException("QuickBooks returned no company preferences.");
        }
        XmlNode salesTax = preferences.SelectSingleNode("./*[local-name()='SalesTaxPreferences']");
        XmlNode inventory = preferences.SelectSingleNode("./*[local-name()='ItemsAndInventoryPreferences']");
        XmlNode locations = preferences.SelectSingleNode("./*[local-name()='MultiLocationInventoryPreferences']");
        return new List<Dictionary<string, object>>
        {
            new Dictionary<string, object>
            {
                { "customerTaxCodeEnabled", ParseActiveStatus(salesTax == null ? null : ChildText(salesTax, "IsUsingCustomerTaxCode")) },
                { "vendorTaxCodeEnabled", ParseActiveStatus(salesTax == null ? null : ChildText(salesTax, "IsUsingVendorTaxCode")) },
                { "amountsIncludeTax", ParseActiveStatus(salesTax == null ? null : ChildText(salesTax, "IsUsingAmountsIncludeTax")) },
                { "defaultTaxableCode", TaxPreferenceRef(salesTax, "DefaultTaxableSalesTaxCodeRef") },
                { "defaultNonTaxableCode", TaxPreferenceRef(salesTax, "DefaultNonTaxableSalesTaxCodeRef") },
                { "defaultTaxItem", TaxPreferenceRef(salesTax, "DefaultItemSalesTaxRef") },
                { "multiLocationAvailable", ParseActiveStatus(locations == null ? null : ChildText(locations, "IsMultiLocationInventoryAvailable")) },
                { "multiLocationEnabled", ParseActiveStatus(locations == null ? null : ChildText(locations, "IsMultiLocationInventoryEnabled")) },
                { "lotTracking", inventory == null ? null : ChildText(inventory, "IsTrackingSerialOrLotNumber") },
                { "expirationDateEnabled", ParseActiveStatus(inventory == null ? null : ChildText(inventory, "IsInventoryExpirationDateEnabled")) },
                { "salesTransactionTrackingEnabled", ParseActiveStatus(inventory == null ? null : ChildText(inventory, "isTrackingOnSalesTransactionsEnabled")) }
            }
        };
    }

    private static Dictionary<string, object> TaxPreferenceRef(XmlNode parent, string elementName)
    {
        var reference = parent == null ? null : parent.SelectSingleNode("./*[local-name()='" + elementName + "']");
        return new Dictionary<string, object>
        {
            { "listId", reference == null ? null : ChildText(reference, "ListID") },
            { "fullName", reference == null ? null : ChildText(reference, "FullName") }
        };
    }

    private static List<Dictionary<string, object>> QueryInvoiceCustomFields(dynamic processor, string ticket, string version)
    {
        XmlNode response = RunQuery(
            processor,
            ticket,
            version,
            BuildFilteredQuery(version, "DataExtDefQueryRq", "AssignToObject", "Invoice"),
            "DataExtDefQueryRs");
        var records = new List<Dictionary<string, object>>();
        foreach (XmlNode node in response.SelectNodes("./*[local-name()='DataExtDefRet']"))
        {
            var assignedTo = node.SelectNodes("./*[local-name()='AssignToObject']")
                .Cast<XmlNode>()
                .Select(value => value.InnerText)
                .ToList();
            if (assignedTo.Count == 0 || !assignedTo.Contains("Invoice", StringComparer.Ordinal))
            {
                continue;
            }
            records.Add(new Dictionary<string, object>
            {
                { "ownerId", ChildText(node, "OwnerID") },
                { "dataExtId", ChildText(node, "DataExtID") },
                { "name", ChildText(node, "DataExtName") },
                { "type", ChildText(node, "DataExtType") },
                { "assignToObject", assignedTo },
                { "transactionRequired", ParseActiveStatus(ChildText(node, "DataExtTxnRequire")) },
                { "listRequired", ParseActiveStatus(ChildText(node, "DataExtListRequire")) },
                { "format", ChildText(node, "DataExtFormatString") }
            });
            if (records.Count > 100)
            {
                throw new InvalidDataException("QuickBooks returned too many invoice custom-field definitions.");
            }
        }
        return records;
    }

    private static XmlNode RunQuery(dynamic processor, string ticket, string version, string query, string responseName)
    {
        var document = LoadSafeXml((string)processor.ProcessRequest(ticket, query));
        var response = document.SelectSingleNode("//*[local-name()='" + responseName + "']");
        if (response == null
            || !String.Equals(
                response.Attributes["statusCode"] == null ? null : response.Attributes["statusCode"].Value,
                "0",
                StringComparison.Ordinal))
        {
            throw new InvalidDataException("QuickBooks returned an unsuccessful read-only query.");
        }
        return response;
    }

    private static string BuildEmptyQuery(string version, string requestName)
    {
        return BuildQueryXml(version, requestName, null, null, null);
    }

    private static string BuildFilteredQuery(string version, string requestName, string filterName, string filterValue)
    {
        return BuildQueryXml(version, requestName, filterName, filterValue, null);
    }

    private static string BuildUnrestrictedListQuery(string version, string requestName, string filterName, string filterValue)
    {
        return BuildQueryXml(version, requestName, filterName, filterValue, null);
    }

    private static string BuildQueryXml(string version, string requestName, string filterName, string filterValue, IEnumerable<string> includeFields)
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
            if (filterName != null)
            {
                writer.WriteElementString(filterName, filterValue);
            }
            if (includeFields != null)
            {
                foreach (var field in includeFields)
                    writer.WriteElementString("IncludeRetElement", field);
            }
            writer.WriteEndElement();
            writer.WriteEndElement();
            writer.WriteEndElement();
        }
        return builder.ToString();
    }

    private static List<Dictionary<string, object>> QueryRecordsByName(
        dynamic processor,
        string ticket,
        string entity,
        string searchName,
        string version)
    {
        if (entity != "customer" && entity != "item" && entity != "inventory_site")
            throw new InvalidDataException("Name lookup is supported only for customers, items, and inventory sites.");
        var config = QueryConfig.For(entity);
        var records = new Dictionary<string, Dictionary<string, object>>(StringComparer.Ordinal);
        var queries = entity == "inventory_site"
            ? new[] { BuildNameQuery(version, config.RequestName, searchName, true) }
            : new[]
        {
            BuildNameQuery(version, config.RequestName, searchName, false),
            BuildNameQuery(version, config.RequestName, searchName, true)
        };
        foreach (var query in queries)
        {
            var response = (string)processor.ProcessRequest(ticket, query);
            var document = LoadSafeXml(response);
            var queryResponse = document.SelectSingleNode("//" + config.ResponseName);
            if (queryResponse == null
                || !String.Equals(
                    queryResponse.Attributes["statusCode"] == null ? null : queryResponse.Attributes["statusCode"].Value,
                    "0",
                    StringComparison.Ordinal))
            {
                throw new InvalidDataException("QuickBooks returned an unsuccessful read-only name query.");
            }

            foreach (XmlNode node in queryResponse.ChildNodes)
            {
                if (node.NodeType != XmlNodeType.Element || !node.LocalName.EndsWith("Ret", StringComparison.Ordinal))
                {
                    continue;
                }
                var listId = ChildText(node, "ListID");
                var name = ChildText(node, "Name");
                var fullName = ChildText(node, "FullName");
                var maximumNameLength = entity == "customer" ? 41 : 31;
                var maximumFullNameLength = entity == "customer" ? 209 : 159;
                if (!IsValidListId(listId)
                    || !IsBoundedString(name, maximumNameLength, false)
                    || !IsBoundedString(fullName, maximumFullNameLength, false))
                {
                    throw new InvalidDataException("QuickBooks returned a malformed name-query record.");
                }
                var record = new Dictionary<string, object>
                {
                    { "listId", listId },
                    { "name", name },
                    { "fullName", fullName },
                    { "found", true },
                    { "active", ParseActiveStatus(ChildText(node, "IsActive")) }
                };
                if (entity == "customer")
                {
                    var companyName = ChildText(node, "CompanyName");
                    if (companyName != null && companyName.Length > 41)
                    {
                        throw new InvalidDataException("QuickBooks returned an oversized customer CompanyName.");
                    }
                    record["companyName"] = companyName;
                }
                else if (entity == "item")
                {
                    var manufacturerPartNumber = ChildText(node, "ManufacturerPartNumber");
                    var salesDescription = ChildText(node, "SalesDesc");
                    if ((manufacturerPartNumber != null && manufacturerPartNumber.Length > 31)
                        || (salesDescription != null && salesDescription.Length > 4095))
                    {
                        throw new InvalidDataException("QuickBooks returned an oversized item field.");
                    }
                    record["manufacturerPartNumber"] = manufacturerPartNumber;
                    record["salesDescription"] = salesDescription;
                }
                Dictionary<string, object> existing;
                if (records.TryGetValue(listId, out existing))
                {
                    if (!RecordsAgree(existing, record, entity))
                    {
                        throw new InvalidDataException("QuickBooks returned conflicting data for one name-query ListID.");
                    }
                    continue;
                }
                records.Add(listId, record);
                if (records.Count > MaxListIds)
                {
                    throw new InvalidDataException("QuickBooks returned too many exact-name candidates.");
                }
            }
        }
        return records.Values.ToList();
    }

    private static bool RecordsAgree(
        Dictionary<string, object> left,
        Dictionary<string, object> right,
        string entity)
    {
        var keys = entity == "customer"
            ? new[] { "name", "fullName", "active", "companyName" }
            : entity == "item"
                ? new[] { "name", "fullName", "active", "manufacturerPartNumber", "salesDescription" }
                : new[] { "name", "fullName", "active" };
        return keys.All(key => Object.Equals(left[key], right[key]));
    }

    private static object ParseActiveStatus(string value)
    {
        bool active;
        return Boolean.TryParse(value, out active) ? (object)active : null;
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
            if (requestName == "ItemQueryRq")
            {
                writer.WriteElementString("IncludeRetElement", "ManufacturerPartNumber");
            }
            writer.WriteEndElement();
            writer.WriteEndElement();
            writer.WriteEndElement();
        }
        return builder.ToString();
    }

    private static string BuildNameQuery(string version, string requestName, string name, bool fullName)
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
            if (fullName)
            {
                writer.WriteElementString("FullName", name);
            }
            else
            {
                writer.WriteElementString("MaxReturned", (MaxListIds + 1).ToString(CultureInfo.InvariantCulture));
                writer.WriteStartElement("NameFilter");
                writer.WriteElementString("MatchCriterion", "Equal");
                writer.WriteElementString("Name", name);
                writer.WriteEndElement();
            }
            foreach (var field in new[] { "ListID", "Name", "FullName", "IsActive" })
            {
                writer.WriteElementString("IncludeRetElement", field);
            }
            if (requestName == "CustomerQueryRq")
            {
                writer.WriteElementString("IncludeRetElement", "CompanyName");
            }
            else if (requestName == "ItemQueryRq")
            {
                writer.WriteElementString("IncludeRetElement", "ManufacturerPartNumber");
                writer.WriteElementString("IncludeRetElement", "SalesDesc");
            }
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
        var companyProcessor = new FakeQueryProcessor(
            "<QBXML><QBXMLMsgsRs><CompanyQueryRs statusCode=\"0\"><CompanyRet><CompanyName>MFC &amp; Operations</CompanyName><LegalCompanyName>MET FOOD CORP</LegalCompanyName></CompanyRet></CompanyQueryRs></QBXMLMsgsRs></QBXML>");
        var companyIdentity = QueryCompanyIdentity(companyProcessor, "test-ticket", "16.0");
        check(
            String.Equals(StringValue(companyIdentity, "companyName"), "MFC & Operations", StringComparison.Ordinal)
                && String.Equals(StringValue(companyIdentity, "legalCompanyName"), "MET FOOD CORP", StringComparison.Ordinal),
            "company identity query parses escaped CompanyName and LegalCompanyName from CompanyQueryRs");
        check(
            companyProcessor.Requests.Count == 1
                && companyProcessor.Requests[0].Contains("<CompanyQueryRq")
                && companyProcessor.Requests[0].Contains("<IncludeRetElement>CompanyName</IncludeRetElement>")
                && companyProcessor.Requests[0].Contains("<IncludeRetElement>LegalCompanyName</IncludeRetElement>"),
            "company identity query uses only the documented CompanyQueryRq fields");
        var changedCompanyIdentity = new Dictionary<string, object>
        {
            { "companyName", "MFC & Operations" },
            { "legalCompanyName", "Different Legal Entity" }
        };
        check(
            !String.Equals(
                CompanyFingerprint(@"C:\MFC\company.qbw", companyIdentity, secret),
                CompanyFingerprint(@"C:\MFC\company.qbw", changedCompanyIdentity, secret),
                StringComparison.Ordinal),
            "company fingerprint changes when accounting-company identity changes at the same path");
        var missingCompanyIdentityRejected = false;
        try
        {
            QueryCompanyIdentity(
                new FakeQueryProcessor("<QBXML><QBXMLMsgsRs><CompanyQueryRs statusCode=\"0\"><CompanyRet><CompanyName>Only a trade name</CompanyName></CompanyRet></CompanyQueryRs></QBXMLMsgsRs></QBXML>"),
                "test-ticket",
                "16.0");
        }
        catch (CompanyIdentityResponseException)
        {
            missingCompanyIdentityRejected = true;
        }
        check(missingCompanyIdentityRejected, "company diagnostics fail closed when LegalCompanyName is missing");
        var sdkComFailure = CompanyIdentityFailure(
            new TargetInvocationException("Sensitive path must not be surfaced.", new COMException("Sensitive company data.", unchecked((int)0x8004041D))),
            "SDK_BEGIN_SESSION");
        check(StringValue(sdkComFailure, "diagnosticCode") == "SDK_COM_8004041D"
            && StringValue(sdkComFailure, "message").Contains("SDK_BEGIN_SESSION")
            && StringValue(sdkComFailure, "message").Contains("0x8004041D")
            && !StringValue(sdkComFailure, "message").Contains("Sensitive")
            && !StringValue(sdkComFailure, "message").Contains("company data"),
            "company identity diagnostic preserves a nested COM HRESULT without exposing exception text");
        var rejectedCompanyQuery = false;
        try
        {
            QueryCompanyIdentity(
                new FakeQueryProcessor("<QBXML><QBXMLMsgsRs><CompanyQueryRs statusCode=\"3140\" statusSeverity=\"Error\" statusMessage=\"Sensitive QuickBooks response text\"><CompanyRet/></CompanyQueryRs></QBXMLMsgsRs></QBXML>"),
                "test-ticket",
                "16.0");
        }
        catch (CompanyQueryStatusException queryError)
        {
            var safeStatus = CompanyIdentityFailure(queryError, "COMPANY_QUERY");
            rejectedCompanyQuery = StringValue(safeStatus, "diagnosticCode") == "COMPANY_QUERY_QBXML_STATUS_3140"
                && StringValue(safeStatus, "message").Contains("statusCode 3140")
                && !StringValue(safeStatus, "message").Contains("Sensitive");
        }
        check(rejectedCompanyQuery, "company identity diagnostic reports sanitized QuickBooks qbXML status codes");
        var malformedCompanyResponse = CompanyIdentityFailure(
            new XmlException("Sensitive XML content."),
            "COMPANY_QUERY");
        check(StringValue(malformedCompanyResponse, "diagnosticCode") == "COMPANY_QUERY_XML_INVALID"
            && !StringValue(malformedCompanyResponse, "message").Contains("Sensitive"),
            "company identity diagnostic distinguishes malformed qbXML without echoing response content");
        var cleanupFailure = CompanyIdentityFailure(new COMException("Sensitive details.", unchecked((int)0x80004005)), "SDK_SESSION_CLEANUP");
        check(StringValue(cleanupFailure, "diagnosticCode") == "SDK_COM_80004005"
            && !StringValue(cleanupFailure, "message").Contains("Sensitive"),
            "SDK cleanup failures retain only a sanitized HRESULT");
        var validSubmitRequest = new Dictionary<string, object>
        {
            { "protocolVersion", "1" },
            { "requestId", "11111111-1111-4111-8111-111111111111" },
            { "operation", "submit" },
            { "transactionType", "invoice" },
            { "idempotencyKey", new string('a', 64) },
            { "source", new Dictionary<string, object>
                {
                    { "id", 1m },
                    { "revision", new string('a', 64) }
                }
            },
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
                                { "salesTaxCode", new Dictionary<string, object> { { "listId", "TAX-1" } } },
                                { "site", "Main Warehouse" },
                                { "lotNumber", "LOT-1" },
                                { "unitOfMeasure", "Box" }
                            }
                        }
                    },
                    { "expectedSubtotal", "20.00" },
                    { "expectedTax", null },
                    { "expectedTotal", null }
                }
            }
        };
        check(ValidateSubmitRequest(validSubmitRequest), "invoice submit contract accepts an in-range SDK reference");
        var inactiveCustomerProcessor = new FakeQueryProcessor(
            "<QBXML><QBXMLMsgsRs><CustomerQueryRs statusCode=\"0\"><CustomerRet><ListID>CUST-1</ListID><Name>Mock Customer</Name><FullName>Mock Customer</FullName><IsActive>false</IsActive></CustomerRet></CustomerQueryRs></QBXMLMsgsRs></QBXML>");
        var inactiveCustomer = VerifyInvoiceReferences(inactiveCustomerProcessor, "test-ticket", "16.0", validSubmitRequest);
        check(inactiveCustomer != null
            && inactiveCustomer.Item1 == "CUSTOMER_REFERENCE_INVALID"
            && inactiveCustomerProcessor.Requests[0].Contains("<CustomerQueryRq")
            && !inactiveCustomerProcessor.Requests[0].Contains("InvoiceAddRq"),
            "mock inactive customer reference fails preflight before any write request");
        var activeInvoiceProcessor = new FakeQueryProcessor(
            "<QBXML><QBXMLMsgsRs><CustomerQueryRs statusCode=\"0\"><CustomerRet><ListID>CUST-1</ListID><Name>Mock Customer</Name><FullName>Mock Customer</FullName><IsActive>true</IsActive></CustomerRet></CustomerQueryRs></QBXMLMsgsRs></QBXML>",
            "<QBXML><QBXMLMsgsRs><ItemQueryRs statusCode=\"0\"><ItemInventoryRet><ListID>ITEM-1</ListID><Name>Mock Item</Name><FullName>Mock Item</FullName><IsActive>true</IsActive><ManufacturerPartNumber>MPN-1</ManufacturerPartNumber></ItemInventoryRet></ItemQueryRs></QBXMLMsgsRs></QBXML>",
            "<QBXML><QBXMLMsgsRs><SalesTaxCodeQueryRs statusCode=\"0\"><SalesTaxCodeRet><ListID>TAX-1</ListID><Name>Tax</Name><IsActive>true</IsActive></SalesTaxCodeRet></SalesTaxCodeQueryRs></QBXMLMsgsRs></QBXML>",
            "<QBXML><QBXMLMsgsRs><PreferencesQueryRs statusCode=\"0\"><PreferencesRet><SalesTaxPreferences><IsUsingCustomerTaxCode>true</IsUsingCustomerTaxCode></SalesTaxPreferences><MultiLocationInventoryPreferences><IsMultiLocationInventoryAvailable>true</IsMultiLocationInventoryAvailable><IsMultiLocationInventoryEnabled>true</IsMultiLocationInventoryEnabled></MultiLocationInventoryPreferences><ItemsAndInventoryPreferences><IsTrackingSerialOrLotNumber>LotNumber</IsTrackingSerialOrLotNumber></ItemsAndInventoryPreferences></PreferencesRet></PreferencesQueryRs></QBXMLMsgsRs></QBXML>",
            "<QBXML><QBXMLMsgsRs><InventorySiteQueryRs statusCode=\"0\"><InventorySiteRet><ListID>SITE-1</ListID><Name>Main Warehouse</Name><FullName>Main Warehouse</FullName><IsActive>true</IsActive></InventorySiteRet></InventorySiteQueryRs></QBXMLMsgsRs></QBXML>",
            "<QBXML><QBXMLMsgsRs><ItemQueryRs statusCode=\"0\"><ItemInventoryRet><ListID>ITEM-1</ListID><Name>Mock Item</Name><FullName>Mock Item</FullName><IsActive>true</IsActive><UnitOfMeasureSetRef><ListID>UOM-1</ListID><FullName>Box Set</FullName></UnitOfMeasureSetRef></ItemInventoryRet></ItemQueryRs></QBXMLMsgsRs></QBXML>",
            "<QBXML><QBXMLMsgsRs><UnitOfMeasureSetQueryRs statusCode=\"0\"><UnitOfMeasureSetRet><ListID>UOM-1</ListID><IsActive>true</IsActive><UnitOfMeasureType>Count</UnitOfMeasureType><BaseUnit><Name>Box</Name><Abbreviation>box</Abbreviation></BaseUnit><DefaultUnit><UnitUsedFor>Sales</UnitUsedFor><Unit>Box</Unit></DefaultUnit></UnitOfMeasureSetRet></UnitOfMeasureSetQueryRs></QBXMLMsgsRs></QBXML>");
        var activeInvoiceReferences = VerifyInvoiceReferences(activeInvoiceProcessor, "test-ticket", "16.0", validSubmitRequest);
        check(activeInvoiceReferences == null
            && activeInvoiceProcessor.Requests.Count == 7
            && activeInvoiceProcessor.Requests.All(request => request.Contains("QueryRq") && !request.Contains("AddRq")),
            "mock active customer, item, tax, site, lot preference, and sales U/M references pass preflight using read-only queries"
            + (activeInvoiceReferences == null
                ? " (requests=" + activeInvoiceProcessor.Requests.Count + ")"
                : " (" + activeInvoiceReferences.Item1 + ": " + activeInvoiceReferences.Item2
                    + "; requests=" + activeInvoiceProcessor.Requests.Count + ")"));
        var submitTransaction = ObjectValue(validSubmitRequest, "transaction");
        submitTransaction["refNumber"] = new string('R', 12);
        check(!ValidateSubmitRequest(validSubmitRequest), "invoice submit contract rejects RefNumber over 11 characters");
        submitTransaction["refNumber"] = "INV-1";
        submitTransaction["poNumber"] = new string('P', 26);
        check(!ValidateSubmitRequest(validSubmitRequest), "invoice submit contract rejects PONumber over 25 characters");
        submitTransaction["poNumber"] = null;
        submitTransaction["memo"] = new string('M', 4096);
        check(!ValidateSubmitRequest(validSubmitRequest), "invoice submit contract rejects Memo over 4095 characters");
        submitTransaction["memo"] = null;
        var multiLine = new Dictionary<string, object>
        {
            { "item", new Dictionary<string, object> { { "listId", "ITEM-2" } } },
            { "description", "Second item" },
            { "quantity", "1" },
            { "rate", "5.00" },
            { "expectedAmount", "5.00" },
            { "salesTaxCode", new Dictionary<string, object> { { "listId", "TAX-1" } } },
            { "site", "Main Warehouse" },
            { "lotNumber", null },
            { "unitOfMeasure", null }
        };
        var lines = ArrayValue(submitTransaction, "lines");
        lines.Add(multiLine);
        submitTransaction["expectedSubtotal"] = "25.00";
        check(ValidateSubmitRequest(validSubmitRequest), "invoice contract supports multiple validated lines");
        var invoiceXml = BuildInvoiceAddRequest(validSubmitRequest, "16.0");
        var invoiceDocument = LoadSafeXml(invoiceXml);
        check(invoiceXml.Contains("<InvoiceAddRq")
            && invoiceDocument.SelectNodes("//*[local-name()='InvoiceLineAdd']").Count == 2
            && invoiceXml.Contains("<InventorySiteRef><FullName>Main Warehouse</FullName></InventorySiteRef>")
            && invoiceXml.Contains("<LotNumber>LOT-1</LotNumber>")
            && invoiceXml.Contains("<UnitOfMeasure>Box</UnitOfMeasure>"),
            "InvoiceAdd XML contains multiple lines and documented site, lot, and unit fields");
        check(invoiceXml.Contains("<ListID>CUST-1</ListID>")
            && invoiceXml.Contains("<ListID>ITEM-1</ListID>")
            && invoiceXml.Contains("<SalesTaxCodeRef><ListID>TAX-1</ListID></SalesTaxCodeRef>")
            && invoiceXml.Contains("<TxnDate>2026-10-23</TxnDate>")
            && invoiceXml.Contains("<RefNumber>INV-1</RefNumber>"),
            "InvoiceAdd XML uses ListID references and the validated date and reference");
        submitTransaction["expectedSubtotal"] = "20.00";
        submitTransaction["expirationDate"] = "2026-12-31";
        ((Dictionary<string, object>)lines[0])["expirationDate"] = "2026-12-31";
        check(!ValidateSubmitRequest(validSubmitRequest), "unverified line-level expiration dates are rejected before XML creation");
        ((Dictionary<string, object>)lines[0]).Remove("expirationDate");
        submitTransaction.Remove("expirationDate");
        lines.RemoveAt(1);
        const string mockedRequestId = "11111111-1111-4111-8111-111111111111";
        var successXml = "<QBXML><QBXMLMsgsRs><InvoiceAddRs requestID=\"" + mockedRequestId + "\" statusCode=\"0\" statusSeverity=\"Info\" statusMessage=\"Status OK\"><InvoiceRet><TxnID>TXN-123</TxnID><TxnNumber>456</TxnNumber></InvoiceRet></InvoiceAddRs></QBXMLMsgsRs></QBXML>";
        var parsedSuccess = ParseInvoiceAddResponse(successXml, mockedRequestId);
        check(StringValue(parsedSuccess, "outcome") == "succeeded"
            && StringValue(parsedSuccess, "txnId") == "TXN-123"
            && StringValue(parsedSuccess, "txnNumber") == "456",
            "InvoiceAddRs success response requires and parses TxnID and TxnNumber");
        var mockWrite = SendInvoiceAdd("mock-request", value => successXml, new string('b', 64), mockedRequestId);
        check(mockWrite.Status == "ok"
            && StringValue(mockWrite.Data, "txnId") == "TXN-123"
            && StringValue(mockWrite.Data, "txnNumber") == "456",
            "mock SDK transport confirms a successful InvoiceAdd response");
        var mockSdkFailure = SendInvoiceAdd("mock-request", value =>
        {
            throw new InvalidOperationException();
        }, new string('b', 64), mockedRequestId);
        check(mockSdkFailure.Status == "unknown"
            && mockSdkFailure.Code == "INVOICE_ADD_OUTCOME_UNKNOWN",
            "mock SDK exception after dispatch is classified as unknown, never retried");
        var missingIdentifiers = ParseInvoiceAddResponse("<QBXML><QBXMLMsgsRs><InvoiceAddRs requestID=\"" + mockedRequestId + "\" statusCode=\"0\"><InvoiceRet><TxnNumber>456</TxnNumber></InvoiceRet></InvoiceAddRs></QBXMLMsgsRs></QBXML>", mockedRequestId);
        check(StringValue(missingIdentifiers, "outcome") == "unknown",
            "successful InvoiceAddRs without TxnID is not treated as posted");
        var parsedRejected = ParseInvoiceAddResponse("<QBXML><QBXMLMsgsRs><InvoiceAddRs requestID=\"" + mockedRequestId + "\" statusCode=\"3140\" statusSeverity=\"Error\" statusMessage=\"Invalid item\"><InvoiceRet/></InvoiceAddRs></QBXMLMsgsRs></QBXML>", mockedRequestId);
        check(StringValue(parsedRejected, "outcome") == "rejected"
            && StringValue(parsedRejected, "diagnosticCode") == "QUICKBOOKS_REJECTED"
            && !StringValue(parsedRejected, "message").Contains("<?xml"),
            "QuickBooks InvoiceAdd status errors become sanitized definitive rejections");
        check(StringValue(ParseInvoiceAddResponse("<QBXML><QBXMLMsgsRs/></QBXML>", mockedRequestId), "outcome") == "unknown"
            && StringValue(ParseInvoiceAddResponse("<malformed>", mockedRequestId), "outcome") == "unknown",
            "missing and malformed InvoiceAddRs responses remain ambiguous");
        check(StringValue(ParseInvoiceAddResponse(successXml, "22222222-2222-4222-8222-222222222222"), "outcome") == "unknown",
            "InvoiceAddRs responses with a mismatched requestID are never accepted as success");
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
        check((bool)ParseActiveStatus("true") && !(bool)ParseActiveStatus("false"),
            "only explicit QuickBooks IsActive booleans are interpreted");
        check(ParseActiveStatus(null) == null
            && ParseActiveStatus("") == null
            && ParseActiveStatus("unknown") == null,
            "missing or malformed QuickBooks IsActive values remain unverified");
        var expectedFingerprint = new string('a', 64);
        check(CompanyFingerprintMatches(expectedFingerprint, expectedFingerprint),
            "actual company fingerprint must match the separately configured expectation");
        check(!CompanyFingerprintMatches(new string('b', 64), expectedFingerprint),
            "company identity mismatch fails closed");
        check(CompanyFingerprintConfigurationError(null) == "COMPANY_FINGERPRINT_NOT_CONFIGURED"
            && CompanyFingerprintConfigurationError("not-a-fingerprint") == "COMPANY_FINGERPRINT_INVALID",
            "missing or invalid expected company fingerprint blocks diagnostics");
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
        string ignoredName;
        check(!ValidateDiagnosticRequest(unsupportedOperation, out ignoredEntity, out ignoredIds, out ignoredName),
            "transaction-add and unknown operations cannot reach QuickBooks diagnostics");
        var exactNameRequest = new Dictionary<string, object>
        {
            { "operation", "diagnose" },
            { "requestId", "88888888-8888-4888-8888-888888888888" },
            { "entity", "item" },
            { "searchName", "Product & Description" }
        };
        check(ValidateDiagnosticRequest(exactNameRequest, out ignoredEntity, out ignoredIds, out ignoredName)
            && ignoredIds == null
            && ignoredName == "Product & Description",
            "name diagnostics accept a bounded exact business-name search");
        var broadNameRequest = new Dictionary<string, object>
        {
            { "operation", "diagnose" },
            { "requestId", "88888888-8888-4888-8888-888888888888" },
            { "entity", "customer" },
            { "searchName", "Customer" },
            { "listIds", new List<object> { "CUST-1" } }
        };
        check(!ValidateDiagnosticRequest(broadNameRequest, out ignoredEntity, out ignoredIds, out ignoredName),
            "name lookup cannot be combined with ListID queries");
        var siteNameRequest = new Dictionary<string, object>
        {
            { "operation", "diagnose" },
            { "requestId", "88888888-8888-4888-8888-888888888888" },
            { "entity", "inventory_site" },
            { "searchName", "Warehouse:North" }
        };
        check(ValidateDiagnosticRequest(siteNameRequest, out ignoredEntity, out ignoredIds, out ignoredName)
            && ignoredName == "Warehouse:North",
            "inventory-site discovery accepts only a bounded exact FullName request");
        var preferencesRequest = new Dictionary<string, object>
        {
            { "operation", "diagnose" },
            { "requestId", "88888888-8888-4888-8888-888888888888" },
            { "entity", "company_preferences" }
        };
        check(ValidateDiagnosticRequest(preferencesRequest, out ignoredEntity, out ignoredIds, out ignoredName)
            && ignoredIds == null,
            "company preference diagnostics do not accept caller-controlled query filters");
        preferencesRequest["listIds"] = new List<object> { "CUST-1" };
        check(!ValidateDiagnosticRequest(preferencesRequest, out ignoredEntity, out ignoredIds, out ignoredName),
            "company preference diagnostics reject unexpected ListIDs");
        var customFieldsRequest = new Dictionary<string, object>
        {
            { "operation", "diagnose" },
            { "requestId", "88888888-8888-4888-8888-888888888888" },
            { "entity", "invoice_custom_fields" }
        };
        check(ValidateDiagnosticRequest(customFieldsRequest, out ignoredEntity, out ignoredIds, out ignoredName),
            "invoice custom-field diagnostics are an allowlisted read-only operation");
        var uomRequest = new Dictionary<string, object>
        {
            { "operation", "diagnose" },
            { "requestId", "88888888-8888-4888-8888-888888888888" },
            { "entity", "item_uom" },
            { "listIds", new List<object> { "ITEM-1" } }
        };
        check(ValidateDiagnosticRequest(uomRequest, out ignoredEntity, out ignoredIds, out ignoredName)
            && ignoredIds.SequenceEqual(new[] { "ITEM-1" }),
            "item U/M diagnostics require caller-supplied bounded item ListIDs");
        var broadNameQuery = BuildNameQuery("16.0", "ItemQueryRq", "Item & Description", false);
        var fullNameQuery = BuildNameQuery("16.0", "ItemQueryRq", "Parent:Item", true);
        check(broadNameQuery.Contains("<MaxReturned>51</MaxReturned>")
            && broadNameQuery.Contains("<MatchCriterion>Equal</MatchCriterion>")
            && broadNameQuery.Contains("<Name>Item &amp; Description</Name>")
            && broadNameQuery.Contains("<IncludeRetElement>ManufacturerPartNumber</IncludeRetElement>")
            && broadNameQuery.Contains("<IncludeRetElement>SalesDesc</IncludeRetElement>")
            && !broadNameQuery.Contains("AddRq"),
            "item name lookup uses a bounded exact NameFilter and returns item metadata without writes");
        check(fullNameQuery.Contains("<FullName>Parent:Item</FullName>")
            && !fullNameQuery.Contains("<NameFilter>")
            && fullNameQuery.Contains("<IncludeRetElement>ManufacturerPartNumber</IncludeRetElement>"),
            "item lookup separately supports an exact FullName query");
        var customerNameQuery = BuildNameQuery("16.0", "CustomerQueryRq", "Customer & Co", false);
        var customerFullNameQuery = BuildNameQuery("16.0", "CustomerQueryRq", "Parent:Customer", true);
        check(customerNameQuery.Contains("<IncludeRetElement>CompanyName</IncludeRetElement>")
            && customerFullNameQuery.Contains("<FullName>Parent:Customer</FullName>"),
            "customer queries retrieve CompanyName and support exact FullName lookup");
        var siteQuery = BuildNameQuery("16.0", "InventorySiteQueryRq", "Warehouse:North", true);
        check(siteQuery.Contains("<FullName>Warehouse:North</FullName>")
            && siteQuery.Contains("<IncludeRetElement>IsActive</IncludeRetElement>")
            && !siteQuery.Contains("<NameFilter>")
            && !siteQuery.Contains("ManufacturerPartNumber")
            && !siteQuery.Contains("AddRq"),
            "inventory-site lookup uses exact FullName and only read-only supported fields");
        var preferencesQuery = BuildEmptyQuery("16.0", "PreferencesQueryRq");
        var customFieldsQuery = BuildFilteredQuery("16.0", "DataExtDefQueryRq", "AssignToObject", "Invoice");
        var unitItemQuery = BuildQueryXml(
            "16.0",
            "ItemQueryRq",
            "ListID",
            "ITEM-1",
            new[] { "ListID", "Name", "FullName", "IsActive", "UnitOfMeasureSetRef" });
        check(preferencesQuery.Contains("<PreferencesQueryRq")
            && !preferencesQuery.Contains("AddRq")
            && customFieldsQuery.Contains("<AssignToObject>Invoice</AssignToObject>")
            && !customFieldsQuery.Contains("AddRq")
            && unitItemQuery.Contains("<IncludeRetElement>UnitOfMeasureSetRef</IncludeRetElement>")
            && !unitItemQuery.Contains("AddRq"),
            "configuration and U/M templates generate constrained query-only qbXML");
        var unitProcessor = new FakeQueryProcessor(
            "<QBXML><QBXMLMsgsRs><ItemQueryRs statusCode=\"0\"><ItemInventoryRet><ListID>ITEM-1</ListID><Name>Product</Name><FullName>Product</FullName><IsActive>true</IsActive><UnitOfMeasureSetRef><ListID>UOM-1</ListID><FullName>Weight</FullName></UnitOfMeasureSetRef></ItemInventoryRet></ItemQueryRs></QBXMLMsgsRs></QBXML>",
            "<QBXML><QBXMLMsgsRs><UnitOfMeasureSetQueryRs statusCode=\"0\"><UnitOfMeasureSetRet><ListID>UOM-1</ListID><IsActive>true</IsActive><UnitOfMeasureType>Weight</UnitOfMeasureType><BaseUnit><Name>Pound</Name><Abbreviation>lb</Abbreviation></BaseUnit><RelatedUnit><Name>Ton</Name><Abbreviation>tn</Abbreviation><ConversionRatio>2000</ConversionRatio></RelatedUnit><DefaultUnit><UnitUsedFor>Sales</UnitUsedFor><Unit>Pound</Unit></DefaultUnit></UnitOfMeasureSetRet></UnitOfMeasureSetQueryRs></QBXMLMsgsRs></QBXML>");
        var unitRecords = QueryItemUnits(unitProcessor, "test-ticket", new List<string> { "ITEM-1" }, "16.0");
        check(unitRecords.Count == 1
            && (bool)unitRecords[0]["found"]
            && (string)unitRecords[0]["unitSetListId"] == "UOM-1"
            && (string)unitRecords[0]["salesDefaultUnit"] == "Pound"
            && ((List<object>)unitRecords[0]["units"]).Count == 2
            && unitProcessor.Requests.Count == 2
            && unitProcessor.Requests.All(request => !request.Contains("AddRq")),
            "mocked item and unit-set query responses preserve U/M evidence without writes");
        var preferencesProcessor = new FakeQueryProcessor(
            "<QBXML><QBXMLMsgsRs><PreferencesQueryRs statusCode=\"0\"><PreferencesRet><SalesTaxPreferences><IsUsingCustomerTaxCode>true</IsUsingCustomerTaxCode><IsUsingVendorTaxCode>false</IsUsingVendorTaxCode><IsUsingAmountsIncludeTax>false</IsUsingAmountsIncludeTax><DefaultTaxableSalesTaxCodeRef><ListID>TAX-1</ListID><FullName>Tax</FullName></DefaultTaxableSalesTaxCodeRef><DefaultNonTaxableSalesTaxCodeRef><ListID>TAX-2</ListID><FullName>Non</FullName></DefaultNonTaxableSalesTaxCodeRef><DefaultItemSalesTaxRef><ListID>TAXITEM-1</ListID><FullName>Sales Tax</FullName></DefaultItemSalesTaxRef></SalesTaxPreferences><MultiLocationInventoryPreferences><IsMultiLocationInventoryAvailable>true</IsMultiLocationInventoryAvailable><IsMultiLocationInventoryEnabled>true</IsMultiLocationInventoryEnabled></MultiLocationInventoryPreferences><ItemsAndInventoryPreferences><IsTrackingSerialOrLotNumber>LotNumber</IsTrackingSerialOrLotNumber><IsInventoryExpirationDateEnabled>true</IsInventoryExpirationDateEnabled><isTrackingOnSalesTransactionsEnabled>true</isTrackingOnSalesTransactionsEnabled></ItemsAndInventoryPreferences></PreferencesRet></PreferencesQueryRs></QBXMLMsgsRs></QBXML>");
        var preferenceRecords = QueryCompanyPreferences(preferencesProcessor, "test-ticket", "16.0");
        check(preferenceRecords.Count == 1
            && (bool)preferenceRecords[0]["customerTaxCodeEnabled"]
            && (string)preferenceRecords[0]["lotTracking"] == "LotNumber"
            && (bool)preferenceRecords[0]["expirationDateEnabled"]
            && preferencesProcessor.Requests.Count == 1
            && !preferencesProcessor.Requests[0].Contains("AddRq"),
            "mocked preference response reports only observed tax, site, lot, and expiration settings");
        var customFieldProcessor = new FakeQueryProcessor(
            "<QBXML><QBXMLMsgsRs><DataExtDefQueryRs statusCode=\"0\"><DataExtDefRet><OwnerID>0</OwnerID><DataExtID>17</DataExtID><DataExtName>Expiration Date</DataExtName><DataExtType>DATETIMETYPE</DataExtType><AssignToObject>Invoice</AssignToObject><DataExtTxnRequire>false</DataExtTxnRequire><DataExtListRequire>false</DataExtListRequire></DataExtDefRet></DataExtDefQueryRs></QBXMLMsgsRs></QBXML>");
        var customFieldRecords = QueryInvoiceCustomFields(customFieldProcessor, "test-ticket", "16.0");
        check(customFieldRecords.Count == 1
            && (string)customFieldRecords[0]["ownerId"] == "0"
            && (string)customFieldRecords[0]["name"] == "Expiration Date"
            && customFieldProcessor.Requests.Count == 1
            && customFieldProcessor.Requests[0].Contains("<AssignToObject>Invoice</AssignToObject>")
            && !customFieldProcessor.Requests[0].Contains("AddRq"),
            "mocked invoice custom-field definitions are read-only and preserve the SDK OwnerID");
        var siteProcessor = new FakeQueryProcessor(
            "<QBXML><QBXMLMsgsRs><InventorySiteQueryRs statusCode=\"0\"><InventorySiteRet><ListID>SITE-1</ListID><Name>North</Name><FullName>Warehouse:North</FullName><IsActive>true</IsActive></InventorySiteRet></InventorySiteQueryRs></QBXMLMsgsRs></QBXML>");
        var siteRecords = QueryRecordsByName(siteProcessor, "test-ticket", "inventory_site", "Warehouse:North", "16.0");
        check(siteRecords.Count == 1
            && (string)siteRecords[0]["fullName"] == "Warehouse:North"
            && siteProcessor.Requests.Count == 1
            && siteProcessor.Requests[0].Contains("<FullName>Warehouse:North</FullName>")
            && !siteProcessor.Requests[0].Contains("<NameFilter>")
            && !siteProcessor.Requests[0].Contains("AddRq"),
            "mocked site query response verifies exact FullName parsing without writes");
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

    private sealed class FakeQueryProcessor
    {
        private readonly Queue<string> responses;

        public readonly List<string> Requests = new List<string>();

        public FakeQueryProcessor(params string[] responses)
        {
            this.responses = new Queue<string>(responses);
        }

        public string ProcessRequest(string ticket, string request)
        {
            Requests.Add(request);
            if (responses.Count == 0)
            {
                throw new InvalidOperationException("The mocked QuickBooks query response is exhausted.");
            }
            return responses.Dequeue();
        }
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

        public static DiagnosticResult Ready(
            string entity,
            List<Dictionary<string, object>> records,
            string fingerprint,
            Dictionary<string, object> companyIdentity,
            string version,
            string searchName)
        {
            var data = new Dictionary<string, object>
            {
                { "entity", entity },
                { "records", records },
                { "companyFingerprint", fingerprint },
                { "companyIdentityVerified", true },
                { "companyIdentity", companyIdentity },
                { "qbxmlVersion", version }
            };
            if (searchName != null) data["searchName"] = searchName;
            return new DiagnosticResult { StatusCode = 200, Status = "ok", Data = data };
        }

        public static DiagnosticResult Unavailable(string code)
        {
            return new DiagnosticResult { StatusCode = 503, Status = "unavailable", Code = code };
        }
    }

    private sealed class InvoiceWriteResult
    {
        public int StatusCode;
        public string Status;
        public string Code;
        public Dictionary<string, object> Data;

        public static InvoiceWriteResult Succeeded(Dictionary<string, object> data)
        {
            return new InvoiceWriteResult { StatusCode = 200, Status = "ok", Data = data };
        }

        public static InvoiceWriteResult Rejected(string code, string message)
        {
            return new InvoiceWriteResult
            {
                StatusCode = 422,
                Status = "error",
                Code = String.IsNullOrWhiteSpace(code) ? "INVOICE_REJECTED" : code,
                Data = new Dictionary<string, object>
                {
                    { "outcome", "rejected" },
                    { "message", message ?? "QuickBooks rejected the invoice." }
                }
            };
        }

        public static InvoiceWriteResult Unknown(string code, string message)
        {
            return new InvoiceWriteResult
            {
                StatusCode = 503,
                Status = "unknown",
                Code = String.IsNullOrWhiteSpace(code) ? "INVOICE_ADD_OUTCOME_UNKNOWN" : code,
                Data = new Dictionary<string, object>
                {
                    { "outcome", "unknown" },
                    { "message", message ?? "The invoice result could not be confirmed; reconcile before retrying." }
                }
            };
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
