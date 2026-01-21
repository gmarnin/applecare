## AppleCare module

This module requires Apple Business Manager or Apple School Manager API (AxM) credentials. For directions, see [Create an API account in Apple School Manager](https://support.apple.com/guide/apple-school-manager/create-an-api-account-axm33189f66a/web). The directions are similar for ABM.

### Features

* Automatic client-side syncing every 10-14 days (randomized interval)
* Manual sync options (individual device or bulk)
* Real-time progress tracking on admin page
* Multi-organization support via machine group key or Munki ClientID prefixes
* Intelligent rate limiting with HTTP 429 handling
* Comprehensive device information (model, color, MAC addresses, etc.)

### Configuration

Add your AxM credentials to the `.env` file. Use [create_client_assertion.sh](https://github.com/bartreardon/macscripts/blob/master/AxM/create_client_assertion.sh) to generate the `APPLECARE_CLIENT_ASSERTION` string. For more details, read [Using the new API for Apple Business/School Manager](https://bartreardon.github.io/2025/06/11/using-the-new-api-for-apple-business-school-manager.html).

**Single Organization:**

```bash
# API URL - Choose based on your organization type:
# Apple School Manager: https://api-school.apple.com/v1/
# Apple Business Manager: https://api-business.apple.com/v1/
APPLECARE_API_URL=https://api-school.apple.com/v1/
APPLECARE_CLIENT_ASSERTION="Your Assertion String"
APPLECARE_RATE_LIMIT=40  # Optional, default is 40 calls per minute
```

**Multiple Organizations:**

The module supports multiple organizations by matching either the machine group key prefix or the Munki ClientID prefix (before first hyphen) with org-specific environment variables. This allows different devices to use different Apple Business/School Manager accounts.

**How it works:**
The module uses a priority-based lookup system:

1. **First Priority: Machine Group Key** - Looks up the machine group key from `munkireportinfo.passphrase` for the device
   - Extracts the prefix before the first hyphen (e.g., `6F730D13-451108-AC457…` → `6F730D13`)
   - Looks for org-specific env vars: `6F730D13_APPLECARE_API_URL` and `6F730D13_APPLECARE_CLIENT_ASSERTION`
   - If machine group key exists but config vars are empty, falls back to ClientID

2. **Second Priority: Munki ClientID** - If machine group key not found or config vars empty:
   - Looks up the Munki `ClientIdentifier` for the device
   - Extracts the prefix before the first hyphen (e.g., `org1-device` → `ORG1`)
   - Looks for org-specific env vars: `ORG1_APPLECARE_API_URL` and `ORG1_APPLECARE_CLIENT_ASSERTION`

3. **Third Priority: Default Config** - Falls back to `APPLECARE_API_URL` and `APPLECARE_CLIENT_ASSERTION` if org-specific config is not found

**Example:**

**Using Machine Group Key:**
If a device has machine group key `6F730D13-451108-AC457…` in `munkireportinfo.passphrase`:
```bash
# Organization 1 (using machine group key prefix)
6F730D13_APPLECARE_API_URL=https://api-school.apple.com/v1/
6F730D13_APPLECARE_CLIENT_ASSERTION="Org1 Assertion"
```

**Using Munki ClientID:**
If a device has Munki ClientID `org1-device123`:
```bash
# Organization 1 (using ClientID prefix)
ORG1_APPLECARE_API_URL=https://api-school.apple.com/v1/
ORG1_APPLECARE_CLIENT_ASSERTION="Org1 Assertion"
```

**Complete Example with Fallback:**
```bash
# Default (fallback)
APPLECARE_API_URL=https://api-school.apple.com/v1/
APPLECARE_CLIENT_ASSERTION="Default Assertion"

# Organization 1 (machine group key prefix)
6F730D13_APPLECARE_API_URL=https://api-school.apple.com/v1/
6F730D13_APPLECARE_CLIENT_ASSERTION="Org1 Assertion"

# Organization 2 (ClientID prefix)
ORG2_APPLECARE_API_URL=https://api-business.apple.com/v1/
ORG2_APPLECARE_CLIENT_ASSERTION="Org2 Assertion"
```

**Notes:**
* Prefix matching is case-insensitive (e.g., `org1`, `ORG1`, `Org1` all match `ORG1_*` env vars)
* Machine group key is stored in `munkireportinfo.passphrase` column
* If a device's key/ClientID doesn't have a hyphen, the entire value is used as the prefix
* Tokens are cached per organization to avoid regenerating them for each device
* Machine group key matching takes precedence over ClientID matching

Add `applecare` to the `Modules` section in your `.env` file.

**Optional: Reseller Name Mapping**

The module can translate reseller IDs (from `purchase_source_id`) to readable names using a YAML configuration file. Create `local/module_configs/applecare_resellers.yml`:

```yaml
# Maps purchase_source_id values to readable reseller names
# Note: Keys must be quoted to ensure proper parsing with PHP 8.1+
'1A2B3C': 'Reseller Company Name'
'4D5E6F': 'Another Reseller Inc'
'789ABC': 'Example Reseller'
'DEF123': 'Apple Retail Business'
# Add more reseller mappings as needed
```

When configured, reseller names will be displayed instead of IDs in the AppleCare tab and listings. Matching is case-insensitive.

**Important:** All reseller ID keys must be quoted (e.g., `'1A2B3C'` instead of `1A2B3C`) to ensure compatibility with PHP 8.1+ and prevent type mismatch issues.

### Syncing

**Automatic Syncing (Recommended):**

Clients automatically sync their AppleCare data every 10-14 days (randomized) during normal MunkiReport check-ins. 

**Manual Syncing Options:**

1. **Individual Device**: Navigate to client detail page → AppleCare tab → Click "Sync Now" button. Syncs only that device and takes a few seconds.

2. **Bulk via Admin Page**: Navigate to Admin → Update AppleCare data → Click "Run AppleCare Sync". Shows real-time progress bar with device counts and sync output. **Note:** Large syncs (100+ devices) may timeout due to PHP execution limits. Use CLI script for large syncs.

3. **Bulk via CLI Script**: Run `php sync_applecare.php` from command line. Recommended for large syncs or scheduled automation. Can be scheduled with cron or other task schedulers.

### Admin Page Features

The AppleCare admin page (`Admin` → `Update AppleCare data`) includes:

* **System Status Panel**: API URL and Client Assertion configuration status, rate limit setting, masked API URL display
* **Exclude Existing Records Option**: Checkbox to exclude devices that already have AppleCare records from bulk sync. Useful for syncing only new devices or devices that haven't been synced yet. Device count updates dynamically based on checkbox state.
* **Sync Progress Tracking**: Real-time progress bar, device counts, estimated time remaining, color-coded sync output (green: success, yellow: warnings, red: errors), completion summary
* **Rate Limiting**: Moving window rate limiting (60-second rolling window), uses 80% of configured rate limit to allow room for background updates, automatic HTTP 429 handling with `Retry-After` support, configurable via `APPLECARE_RATE_LIMIT`. The system automatically spaces device syncs to prevent hitting rate limits while maximizing throughput.

### Client Detail Page Features

* **AppleCare Tab**: Displays coverage records and device information, "Sync Now" button, "Last fetched" timestamp, links to listings/reports
* **Detail Widgets**: AppleCare Detail Widget (coverage summary) for device summary tab

### Table Schema

Based on [AppleCareCoverage.Attributes](https://developer.apple.com/documentation/appleschoolmanagerapi/applecarecoverage/attributes-data.dictionary) and [OrgDevice.Attributes](https://developer.apple.com/documentation/appleschoolmanagerapi/orgdevice/attributes-data.dictionary).

#### AppleCare Coverage Fields

* description - varchar(255) - Description of device coverage.
* status - varchar(255) - The current status of device coverage. Possible values: 'ACTIVE', 'INACTIVE'
* startDateTime - DATETIME - Date when coverage period commenced.
* endDateTime - DATETIME - Date when coverage period ends for the device. This field isn't applicable for AppleCare+ for Business Essentials.
* contractCancelDateTime - DATETIME - Date when coverage contract was canceled.
* agreementNumber - varchar(255) - Agreement number associated with device coverage. This field isn't applicable for Limited Warranty and AppleCare+ for Business Essentials.
* paymentType - varchar(255) - Payment type of device coverage. Possible values: 'ABE_SUBSCRIPTION', 'PAID_UP_FRONT', 'SUBSCRIPTION', 'NONE'
* isRenewable - Bool - Indicates whether coverage renews after endDateTime for the device. This field isn't applicable for Limited Warranty.
* isCanceled - Bool - Indicates whether coverage is canceled for the device. This field isn't applicable for Limited Warranty and AppleCare+ for Business Essentials.
* last_updated - BIGINT - The last time Apple updated this record in Apple Business/School Manager (from API's updatedDateTime).
* last_fetched - BIGINT - The last time this serial number was checked using the API.
* sync_in_progress - BOOLEAN - Internal flag to prevent concurrent syncs for the same device.

#### Device Information Fields (from Apple Business Manager API)

* model - varchar(255) - Device model name.
* part_number - varchar(255) - Device part number.
* product_family - varchar(255) - Product family (e.g., "Mac", "iPad").
* product_type - varchar(255) - Product type identifier.
* color - varchar(255) - Device color.
* device_capacity - varchar(255) - Device storage capacity.
* device_assignment_status - varchar(255) - Device assignment status (e.g., "ASSIGNED", "UNASSIGNED").
* purchase_source_type - varchar(255) - Purchase source type (e.g., "RESELLER", "DIRECT").
* purchase_source_id - varchar(255) - Purchase source identifier (reseller ID).
* order_number - varchar(255) - Order number.
* order_date - DATETIME - Order date.
* added_to_org_date - DATETIME - Date when device was added to organization.
* released_from_org_date - DATETIME - Date when device was released from organization.
* wifi_mac_address - varchar(255) - Wi-Fi MAC address.
* ethernet_mac_address - varchar(255) - Ethernet MAC address(es), comma-separated if multiple.
* bluetooth_mac_address - varchar(255) - Bluetooth MAC address.

### Troubleshooting

**Sync not triggering automatically:**
* Verify the client script is installed: Check for `/usr/local/munkireport/preflight.d/applecare`
* Check client logs for "Running applecare" and "Requesting applecare" messages
* Verify the plist exists: `/usr/local/munkireport/preflight.d/cache/applecare.plist`

**HTTP 40x errors during sync:**
* Verify API credentials are correct
* Check that the serial number exists in Apple Business/School Manager
* Review server error logs for detailed error messages

**Rate limit issues:**
* The module uses 80% of the configured `APPLECARE_RATE_LIMIT` as the effective rate limit to allow room for background updates
* Moving window rate limiting ensures smooth operation without hitting limits
* Increase `APPLECARE_RATE_LIMIT` if you have a higher API quota
* Run bulk syncs during off-hours
* Use the CLI script for large syncs to avoid PHP timeout limits

**Sync progress not updating:**
* Ensure you keep the admin page open during sync (closing the page stops the sync)
* For large syncs, use the CLI script instead of the web interface
