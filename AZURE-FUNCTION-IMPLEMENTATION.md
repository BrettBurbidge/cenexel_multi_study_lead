# Azure Function Implementation Guide

This guide provides complete specifications for building the Azure Function that receives and processes clinical trial lead submissions from the CenExel WordPress plugin.

## Table of Contents

1. [Overview](#overview)
2. [Request Specification](#request-specification)
3. [Data Model](#data-model)
4. [Security & Authentication](#security--authentication)
5. [Database Schema](#database-schema)
6. [Implementation Example (C#)](#implementation-example-c)
7. [Testing](#testing)
8. [Marketing Analytics](#marketing-analytics)

## Overview

**Function Type**: HTTP Trigger  
**HTTP Method**: POST  
**Content-Type**: `application/json`  
**Authentication**: Azure Function Key + Optional HMAC Signature  

## Request Specification

### Headers

```
Content-Type: application/json
x-functions-key: <azure_function_key>        (optional - Azure Function Key)
x-cenexel-ts: <unix_timestamp>               (optional - HMAC timestamp)
x-cenexel-sig: <hmac_signature>              (optional - HMAC signature)
```

### HMAC Signature Verification

If implementing HMAC authentication, the signature is computed as:

```
signature = base64_encode(hmac_sha256(shared_secret, timestamp + "." + request_body))
```

The signature is based on: `timestamp.request_body` using HMAC-SHA256 with a shared secret.

**Security Recommendations**:
- Verify timestamp is within ±5 minutes of server time
- Reject requests with timestamps too far in the past/future
- Store shared secret in Azure Key Vault

## Data Model

### TypeScript Interface

```typescript
interface LeadSubmission {
  // Location Information
  location_term_id: number;          // WordPress term ID for location
  site_slug: string;                 // Location slug (e.g., "anaheim-ca")
  
  // Patient Information (PII - Handle with care!)
  first_name: string;                // Required
  last_name: string;                 // Required
  email: string;                     // Required, validated email format
  phone: string;                     // Required
  zip: string;                       // Required, ZIP/postal code
  date_of_birth: string;             // Required, format: YYYY-MM-DD
  gender: string;                    // Required, "male" | "female"
  
  // Additional Flags
  is_caregiver: boolean;             // True if caregiver/guardian
  consent: boolean;                  // Required, must be true
  
  // Clinical Trial Selection
  post_ids: number[];                // Required, min 1 item (WordPress post IDs)
  
  // UTM Parameters (Marketing Attribution - Last Touch)
  utm_source: string;                // e.g., "google", "facebook"
  utm_medium: string;                // e.g., "cpc", "email", "social"
  utm_campaign: string;              // e.g., "diabetes_2026"
  utm_content: string;               // e.g., "cta_button", "headline_a"
  utm_term: string;                  // e.g., "diabetes+trial"
  
  // First-Touch Attribution (Original Acquisition)
  first_utm_source: string;
  first_utm_medium: string;
  first_utm_campaign: string;
  first_utm_content: string;
  first_utm_term: string;
  
  // Metadata (Auto-added by WordPress)
  submitted_at: string;              // ISO 8601 timestamp (UTC)
  source: string;                    // Always "cenexelclinicaltrials.com"
  ip: string;                        // Client IP address
  user_agent: string;                // Client user agent (max 255 chars)
}
```

### Example Request Body

```json
{
  "location_term_id": 1796,
  "site_slug": "anaheim-ca",
  "first_name": "John",
  "last_name": "Doe",
  "email": "john.doe@example.com",
  "phone": "714-555-1234",
  "zip": "92801",
  "date_of_birth": "1985-06-15",
  "gender": "male",
  "is_caregiver": false,
  "consent": true,
  "post_ids": [12345, 67890],
  "utm_source": "google",
  "utm_medium": "cpc",
  "utm_campaign": "diabetes_2026",
  "utm_content": "headline_a",
  "utm_term": "diabetes+clinical+trial",
  "first_utm_source": "facebook",
  "first_utm_medium": "social",
  "first_utm_campaign": "awareness_2025",
  "first_utm_content": "",
  "first_utm_term": "",
  "submitted_at": "2026-01-16T08:30:00Z",
  "source": "cenexelclinicaltrials.com",
  "ip": "192.168.1.100",
  "user_agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)..."
}
```

## Security & Authentication

### Validation Requirements

The function MUST validate:

✅ **Required Fields**:
- `first_name`, `last_name`, `email`, `site_slug` are non-empty
- `consent` is explicitly `true`
- `post_ids` array has at least 1 item
- `email` matches valid email format
- `date_of_birth` is valid date (YYYY-MM-DD)
- `gender` is either "male" or "female"

✅ **Security Checks**:
- HMAC signature verification (if `x-cenexel-sig` header present)
- Timestamp freshness (reject if > 5 minutes old)
- Rate limiting per IP address
- Sanitize all inputs

**Return HTTP 400 Bad Request** for validation failures.

### HMAC Implementation (C# Example)

```csharp
public static bool VerifyHmacSignature(string secret, string timestamp, string body, string signature)
{
    var toSign = $"{timestamp}.{body}";
    using (var hmac = new HMACSHA256(Encoding.UTF8.GetBytes(secret)))
    {
        var hash = hmac.ComputeHash(Encoding.UTF8.GetBytes(toSign));
        var computed = Convert.ToBase64String(hash);
        return computed == signature;
    }
}
```

## Database Schema

### SQL Server / Azure SQL

```sql
-- Main leads table
CREATE TABLE clinical_trial_leads (
  id                  INT PRIMARY KEY IDENTITY(1,1),
  lead_uuid           UNIQUEIDENTIFIER DEFAULT NEWID() NOT NULL,
  
  -- Location
  location_term_id    INT NOT NULL,
  site_slug           VARCHAR(100) NOT NULL,
  
  -- Patient Info (PII - Encrypt at rest!)
  first_name          NVARCHAR(100) NOT NULL,
  last_name           NVARCHAR(100) NOT NULL,
  email               NVARCHAR(255) NOT NULL,
  phone               NVARCHAR(50) NOT NULL,
  zip                 NVARCHAR(20) NOT NULL,
  date_of_birth       DATE NOT NULL,
  gender              VARCHAR(10) NOT NULL,
  
  -- Flags
  is_caregiver        BIT DEFAULT 0,
  consent             BIT NOT NULL,
  
  -- UTM Parameters (Last-Touch Attribution)
  utm_source          NVARCHAR(255),
  utm_medium          NVARCHAR(100),
  utm_campaign        NVARCHAR(255),
  utm_content         NVARCHAR(255),
  utm_term            NVARCHAR(255),
  
  -- First-Touch Attribution
  first_utm_source    NVARCHAR(255),
  first_utm_medium    NVARCHAR(100),
  first_utm_campaign  NVARCHAR(255),
  first_utm_content   NVARCHAR(255),
  first_utm_term      NVARCHAR(255),
  
  -- Metadata
  submitted_at        DATETIME2 NOT NULL,
  source              NVARCHAR(100) NOT NULL,
  ip_address          NVARCHAR(45),
  user_agent          NVARCHAR(255),
  
  -- Processing Status
  status              VARCHAR(20) DEFAULT 'pending',
  processed_at        DATETIME2,
  error_message       NVARCHAR(MAX),
  
  -- Timestamps
  created_at          DATETIME2 DEFAULT GETUTCDATE(),
  updated_at          DATETIME2 DEFAULT GETUTCDATE(),
  
  -- Indexes
  CONSTRAINT UK_lead_uuid UNIQUE (lead_uuid),
  INDEX idx_email (email),
  INDEX idx_site_slug (site_slug),
  INDEX idx_submitted_at (submitted_at),
  INDEX idx_status (status),
  INDEX idx_utm_source (utm_source),
  INDEX idx_utm_campaign (utm_campaign),
  INDEX idx_first_utm_source (first_utm_source)
);

-- Study selections (many-to-many)
CREATE TABLE lead_study_selections (
  id           INT PRIMARY KEY IDENTITY(1,1),
  lead_uuid    UNIQUEIDENTIFIER NOT NULL,
  post_id      INT NOT NULL,
  created_at   DATETIME2 DEFAULT GETUTCDATE(),
  
  CONSTRAINT FK_lead_uuid FOREIGN KEY (lead_uuid) 
    REFERENCES clinical_trial_leads(lead_uuid) ON DELETE CASCADE,
  CONSTRAINT UK_lead_study UNIQUE (lead_uuid, post_id)
);

-- Optional: Audit log for tracking changes
CREATE TABLE lead_audit_log (
  id           INT PRIMARY KEY IDENTITY(1,1),
  lead_uuid    UNIQUEIDENTIFIER NOT NULL,
  action       VARCHAR(50) NOT NULL,
  details      NVARCHAR(MAX),
  created_at   DATETIME2 DEFAULT GETUTCDATE(),
  created_by   NVARCHAR(100)
);
```

## Implementation Example (C#)

### Azure Function Skeleton

```csharp
using System;
using System.IO;
using System.Threading.Tasks;
using Microsoft.AspNetCore.Mvc;
using Microsoft.Azure.WebJobs;
using Microsoft.Azure.WebJobs.Extensions.Http;
using Microsoft.AspNetCore.Http;
using Microsoft.Extensions.Logging;
using Newtonsoft.Json;
using System.Data.SqlClient;

public static class LeadSubmissionFunction
{
    [FunctionName("SubmitLead")]
    public static async Task<IActionResult> Run(
        [HttpTrigger(AuthorizationLevel.Function, "post", Route = null)] HttpRequest req,
        ILogger log)
    {
        log.LogInformation("Lead submission received");

        // Read request body
        string requestBody = await new StreamReader(req.Body).ReadToEndAsync();
        
        // Optional: Verify HMAC signature
        if (req.Headers.ContainsKey("x-cenexel-sig"))
        {
            var timestamp = req.Headers["x-cenexel-ts"].ToString();
            var signature = req.Headers["x-cenexel-sig"].ToString();
            var secret = Environment.GetEnvironmentVariable("CENEXEL_SHARED_SECRET");
            
            if (!VerifyHmacSignature(secret, timestamp, requestBody, signature))
            {
                return new UnauthorizedResult();
            }
            
            // Check timestamp freshness (within 5 minutes)
            var ts = long.Parse(timestamp);
            var now = DateTimeOffset.UtcNow.ToUnixTimeSeconds();
            if (Math.Abs(now - ts) > 300)
            {
                return new BadRequestObjectResult(new { error = "Request timestamp expired" });
            }
        }

        // Deserialize payload
        LeadSubmission lead;
        try
        {
            lead = JsonConvert.DeserializeObject<LeadSubmission>(requestBody);
        }
        catch (Exception ex)
        {
            log.LogError($"JSON deserialization failed: {ex.Message}");
            return new BadRequestObjectResult(new { error = "Invalid JSON" });
        }

        // Validate required fields
        var validation = ValidateLead(lead);
        if (!validation.IsValid)
        {
            return new BadRequestObjectResult(new { error = validation.ErrorMessage });
        }

        // Save to database
        try
        {
            var leadId = await SaveLeadToDatabase(lead, log);
            
            return new OkObjectResult(new
            {
                ok = true,
                lead_id = leadId,
                message = "Lead submitted successfully"
            });
        }
        catch (Exception ex)
        {
            log.LogError($"Database error: {ex.Message}");
            return new StatusCodeResult(StatusCodes.Status500InternalServerError);
        }
    }

    private static (bool IsValid, string ErrorMessage) ValidateLead(LeadSubmission lead)
    {
        if (lead == null)
            return (false, "Lead data is required");

        if (string.IsNullOrWhiteSpace(lead.first_name))
            return (false, "First name is required");

        if (string.IsNullOrWhiteSpace(lead.last_name))
            return (false, "Last name is required");

        if (string.IsNullOrWhiteSpace(lead.email))
            return (false, "Email is required");

        if (string.IsNullOrWhiteSpace(lead.site_slug))
            return (false, "Site slug is required");

        if (!lead.consent)
            return (false, "Consent is required");

        if (lead.post_ids == null || lead.post_ids.Length == 0)
            return (false, "At least one study must be selected");

        if (lead.gender != "male" && lead.gender != "female")
            return (false, "Invalid gender value");

        // Validate email format
        try
        {
            var addr = new System.Net.Mail.MailAddress(lead.email);
            if (addr.Address != lead.email)
                return (false, "Invalid email format");
        }
        catch
        {
            return (false, "Invalid email format");
        }

        // Validate date format
        if (!DateTime.TryParse(lead.date_of_birth, out _))
            return (false, "Invalid date of birth format");

        return (true, null);
    }

    private static async Task<Guid> SaveLeadToDatabase(LeadSubmission lead, ILogger log)
    {
        var connString = Environment.GetEnvironmentVariable("SQL_CONNECTION_STRING");
        var leadUuid = Guid.NewGuid();

        using (var conn = new SqlConnection(connString))
        {
            await conn.OpenAsync();

            // Start transaction
            using (var transaction = conn.BeginTransaction())
            {
                try
                {
                    // Insert main lead record
                    var insertLead = @"
                        INSERT INTO clinical_trial_leads (
                            lead_uuid, location_term_id, site_slug,
                            first_name, last_name, email, phone, zip,
                            date_of_birth, gender, is_caregiver, consent,
                            utm_source, utm_medium, utm_campaign, utm_content, utm_term,
                            first_utm_source, first_utm_medium, first_utm_campaign,
                            first_utm_content, first_utm_term,
                            submitted_at, source, ip_address, user_agent, status
                        ) VALUES (
                            @lead_uuid, @location_term_id, @site_slug,
                            @first_name, @last_name, @email, @phone, @zip,
                            @date_of_birth, @gender, @is_caregiver, @consent,
                            @utm_source, @utm_medium, @utm_campaign, @utm_content, @utm_term,
                            @first_utm_source, @first_utm_medium, @first_utm_campaign,
                            @first_utm_content, @first_utm_term,
                            @submitted_at, @source, @ip_address, @user_agent, 'pending'
                        )";

                    using (var cmd = new SqlCommand(insertLead, conn, transaction))
                    {
                        cmd.Parameters.AddWithValue("@lead_uuid", leadUuid);
                        cmd.Parameters.AddWithValue("@location_term_id", lead.location_term_id);
                        cmd.Parameters.AddWithValue("@site_slug", lead.site_slug);
                        cmd.Parameters.AddWithValue("@first_name", lead.first_name);
                        cmd.Parameters.AddWithValue("@last_name", lead.last_name);
                        cmd.Parameters.AddWithValue("@email", lead.email);
                        cmd.Parameters.AddWithValue("@phone", lead.phone);
                        cmd.Parameters.AddWithValue("@zip", lead.zip);
                        cmd.Parameters.AddWithValue("@date_of_birth", DateTime.Parse(lead.date_of_birth));
                        cmd.Parameters.AddWithValue("@gender", lead.gender);
                        cmd.Parameters.AddWithValue("@is_caregiver", lead.is_caregiver);
                        cmd.Parameters.AddWithValue("@consent", lead.consent);
                        cmd.Parameters.AddWithValue("@utm_source", (object)lead.utm_source ?? DBNull.Value);
                        cmd.Parameters.AddWithValue("@utm_medium", (object)lead.utm_medium ?? DBNull.Value);
                        cmd.Parameters.AddWithValue("@utm_campaign", (object)lead.utm_campaign ?? DBNull.Value);
                        cmd.Parameters.AddWithValue("@utm_content", (object)lead.utm_content ?? DBNull.Value);
                        cmd.Parameters.AddWithValue("@utm_term", (object)lead.utm_term ?? DBNull.Value);
                        cmd.Parameters.AddWithValue("@first_utm_source", (object)lead.first_utm_source ?? DBNull.Value);
                        cmd.Parameters.AddWithValue("@first_utm_medium", (object)lead.first_utm_medium ?? DBNull.Value);
                        cmd.Parameters.AddWithValue("@first_utm_campaign", (object)lead.first_utm_campaign ?? DBNull.Value);
                        cmd.Parameters.AddWithValue("@first_utm_content", (object)lead.first_utm_content ?? DBNull.Value);
                        cmd.Parameters.AddWithValue("@first_utm_term", (object)lead.first_utm_term ?? DBNull.Value);
                        cmd.Parameters.AddWithValue("@submitted_at", DateTime.Parse(lead.submitted_at));
                        cmd.Parameters.AddWithValue("@source", lead.source);
                        cmd.Parameters.AddWithValue("@ip_address", lead.ip);
                        cmd.Parameters.AddWithValue("@user_agent", lead.user_agent);

                        await cmd.ExecuteNonQueryAsync();
                    }

                    // Insert study selections
                    var insertStudy = @"
                        INSERT INTO lead_study_selections (lead_uuid, post_id)
                        VALUES (@lead_uuid, @post_id)";

                    foreach (var postId in lead.post_ids)
                    {
                        using (var cmd = new SqlCommand(insertStudy, conn, transaction))
                        {
                            cmd.Parameters.AddWithValue("@lead_uuid", leadUuid);
                            cmd.Parameters.AddWithValue("@post_id", postId);
                            await cmd.ExecuteNonQueryAsync();
                        }
                    }

                    transaction.Commit();
                    log.LogInformation($"Lead saved successfully: {leadUuid}");

                    return leadUuid;
                }
                catch (Exception ex)
                {
                    transaction.Rollback();
                    log.LogError($"Transaction failed: {ex.Message}");
                    throw;
                }
            }
        }
    }

    private static bool VerifyHmacSignature(string secret, string timestamp, string body, string signature)
    {
        var toSign = $"{timestamp}.{body}";
        using (var hmac = new System.Security.Cryptography.HMACSHA256(
            System.Text.Encoding.UTF8.GetBytes(secret)))
        {
            var hash = hmac.ComputeHash(System.Text.Encoding.UTF8.GetBytes(toSign));
            var computed = Convert.ToBase64String(hash);
            return computed == signature;
        }
    }
}

// Data model
public class LeadSubmission
{
    public int location_term_id { get; set; }
    public string site_slug { get; set; }
    public string first_name { get; set; }
    public string last_name { get; set; }
    public string email { get; set; }
    public string phone { get; set; }
    public string zip { get; set; }
    public string date_of_birth { get; set; }
    public string gender { get; set; }
    public bool is_caregiver { get; set; }
    public bool consent { get; set; }
    public int[] post_ids { get; set; }
    public string utm_source { get; set; }
    public string utm_medium { get; set; }
    public string utm_campaign { get; set; }
    public string utm_content { get; set; }
    public string utm_term { get; set; }
    public string first_utm_source { get; set; }
    public string first_utm_medium { get; set; }
    public string first_utm_campaign { get; set; }
    public string first_utm_content { get; set; }
    public string first_utm_term { get; set; }
    public string submitted_at { get; set; }
    public string source { get; set; }
    public string ip { get; set; }
    public string user_agent { get; set; }
}
```

## Testing

### Test Payload

```json
{
  "location_term_id": 1796,
  "site_slug": "anaheim-ca",
  "first_name": "Test",
  "last_name": "User",
  "email": "test@example.com",
  "phone": "555-1234",
  "zip": "12345",
  "date_of_birth": "1990-01-01",
  "gender": "male",
  "is_caregiver": false,
  "consent": true,
  "post_ids": [1, 2],
  "utm_source": "test",
  "utm_medium": "test",
  "utm_campaign": "test_campaign",
  "utm_content": "",
  "utm_term": "",
  "first_utm_source": "",
  "first_utm_medium": "",
  "first_utm_campaign": "",
  "first_utm_content": "",
  "first_utm_term": "",
  "submitted_at": "2026-01-16T00:00:00Z",
  "source": "cenexelclinicaltrials.com",
  "ip": "127.0.0.1",
  "user_agent": "Test"
}
```

### cURL Test

```bash
curl -X POST https://your-function-app.azurewebsites.net/api/SubmitLead \
  -H "Content-Type: application/json" \
  -H "x-functions-key: YOUR_FUNCTION_KEY" \
  -d @test-payload.json
```

## Marketing Analytics

### Key Metrics to Track

1. **Source Performance**: Which traffic sources generate the most leads?
2. **Campaign ROI**: Which campaigns convert best?
3. **First vs Last Touch**: Attribution comparison
4. **Conversion Funnel**: Drop-off analysis by source/campaign

### Sample Queries

**Lead volume by source (last 30 days)**:
```sql
SELECT 
  utm_source,
  utm_medium,
  COUNT(*) as lead_count,
  COUNT(DISTINCT email) as unique_leads
FROM clinical_trial_leads
WHERE submitted_at >= DATEADD(day, -30, GETUTCDATE())
GROUP BY utm_source, utm_medium
ORDER BY lead_count DESC;
```

**Campaign performance**:
```sql
SELECT 
  utm_campaign,
  COUNT(*) as total_leads,
  COUNT(DISTINCT site_slug) as locations,
  COUNT(DISTINCT email) as unique_patients
FROM clinical_trial_leads
WHERE utm_campaign IS NOT NULL
GROUP BY utm_campaign
ORDER BY total_leads DESC;
```

**Attribution comparison**:
```sql
SELECT 
  first_utm_source as first_touch,
  utm_source as last_touch,
  COUNT(*) as conversions
FROM clinical_trial_leads
WHERE first_utm_source IS NOT NULL 
  AND utm_source IS NOT NULL
  AND first_utm_source != utm_source
GROUP BY first_utm_source, utm_source
ORDER BY conversions DESC;
```

## Next Steps

1. **Deploy Azure Function** with the code above
2. **Create Database** using the provided schema
3. **Configure Secrets**:
   - Add `SQL_CONNECTION_STRING` to Azure Function settings
   - Add `CENEXEL_SHARED_SECRET` to Azure Key Vault (if using HMAC)
4. **Update WordPress**:
   - Add `CENEXEL_AZURE_LEAD_ENDPOINT` constant to `wp-config.php`
   - Optionally add `CENEXEL_AZURE_FUNCTION_KEY` and `CENEXEL_AZURE_SHARED_SECRET`
5. **Test End-to-End** with sample submissions
6. **Monitor** with Application Insights
7. **Set Up Alerts** for failures or suspicious activity

## Security Checklist

- [ ] Enable HTTPS only
- [ ] Use Azure Key Vault for secrets
- [ ] Implement rate limiting
- [ ] Encrypt PII data at rest
- [ ] Set up Application Insights logging (exclude PII)
- [ ] Enable Azure SQL firewall rules
- [ ] Implement HMAC signature verification
- [ ] Add IP whitelisting if possible
- [ ] Set up backup and disaster recovery
- [ ] Configure data retention policies
- [ ] Ensure HIPAA compliance (if applicable)

---

**Questions?** Open an issue on [GitHub](https://github.com/BrettBurbidge/cenexel_multi_study_lead/issues).
