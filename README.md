## AppleCare module

This module requires either Apple Business Manager or Apple School Manager API (AxM) credentials. For directions follow how to [Create an API account in Apple School Manager](https://support.apple.com/guide/apple-school-manager/create-an-api-account-axm33189f66a/web). The directions are similar for ABM. 

There are no client side scripts for this module. This module is server side only. 

You must add your (AxM) credentials to the `.env` file.

Use Bart Reardon's excellent [create_client_assertion.sh](https://github.com/bartreardon/macscripts/blob/master/AxM/create_client_assertion.sh)  script to
generate the `APPLECARE_CLIENT_ASSERTION` string. For more details read his blog post [Using the new API for Apple Business/School Manager](https://bartreardon.github.io/2025/06/11/using-the-new-api-for-apple-business-school-manager.html)

```
# Required for AppleCare module

# API URL - Choose based on your organization type:
# Apple School Manager: https://api-school.apple.com/v1/
# Apple Business Manager: https://api-business.apple.com/v1/
APPLECARE_API_URL=https://api-school.apple.com/v1/

# Access Token (Bearer Token) - Generate externally
APPLECARE_CLIENT_ASSERTION="Super Long String Here"

# Optional: Set custom rate limit (default from Apple is 20 calls per minute)
APPLECARE_RATE_LIMIT=20
```

You need to add `applecare` to the `Modules` section in `.env` file 

### How to Sync Data

You can manually check one serial number at a time by going to the summary page for a Mac, click on `AppleCare` in the menu and click on `Sync now`. This only take a few seconds to run. 

These next 2 methods will check for AppleCare data against all the serial numbers in MunkiReport. Both methods are a slow process because of the API rate limiting so do it off hours (especially for the first run). 
 

1. A user with MunkiReport admin rights, go to `Admin`, `Update AppleCare data` and click on `Run AppleCare Sync`. 

2. Manually run `sync_applecare.php`. You can schedule running this script but that is outside the scope of this module. 

```
/local/modules/applecare$ php sync_applecare.php 
MunkiReport root: /usr/local/munkireport
================================================
AppleCare Sync Tool
================================================

✓ Configuration OK
✓ API URL: https://api-school.apple.com/v1/
✓ Rate Limit: 20 calls per minute
✓ Client ID: SCHOOLAPI.d9ee9553-xxxx-xxxx-xxxx-xxxxxxxxxxxx
✓ Using scope: school.api
✓ Generating access token from client assertion...
✓ Access token generated successfully

✓ Database connected

Fetching device list from database...
✓ Found 609 devices


Starting sync...
Rate limit: 20 calls per minute
Estimated time: 31 minutes

Processing C02CXXXXXXXX... OK (2 coverage records)
Processing C02CXXXXXXXX... OK (2 coverage records)
Processing C028XXXXXXXX... OK (2 coverage records)
SKIP (HTTP 429) = Rate limit
SKIP (no coverage)
```


### Table Schema

Based off [AppleCareCoverage.Attributes](https://developer.apple.com/documentation/appleschoolmanagerapi/applecarecoverage/attributes-data.dictionary) API developer documentation 

* description - varchar(255) - Description of device coverage.
* status - varchar(255) - The current status of device coverage. Possible values: ‘ACTIVE’, ‘INACTIVE’
* startDateTime - DATETIME - Date when coverage period commenced.
* endDateTime - DATETIME - Date when coverage period ends for the device. This field isn’t applicable for AppleCare+ for Business Essentials.
* contractCancelDateTime
* agreementNumber - varchar(255) - Agreement number associated with device coverage. This field isn’t applicable for Limited Warranty and AppleCare+ for Business Essentials.
* paymentType - varchar(255) - Payment type of device coverage. Possible values: ‘ABE_SUBSCRIPTION’, ‘PAID_UP_FRONT’, ‘SUBSCRIPTION’, ‘NONE’
* isRenewable - TINTINT - Indicates whether coverage renews after endDateTime for the device. This field isn’t applicable for Limited Warranty.
* isCanceled - TINTINT - Indicates whether coverage is canceled for the device. This field isn’t applicable for Limited Warranty and AppleCare+ for Business Essentials.
* last_updated - TIMESTAMP - The last time this serial number was checked using the API






            