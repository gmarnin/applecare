// Helper function to check if value is true/yes
// Use mr.label() for safe HTML generation (prevents XSS)
var isBooleanTrue = function(value) {
    return value == '1' || value == 'true' || value === true || String(value).toLowerCase() === 'true';
}

// Helper function to format boolean with custom label classes
var formatBooleanLabel = function(col, value, yesClass, noClass) {
    var isYes = isBooleanTrue(value);
    var labelText = (isYes ? i18n.t('Yes') : i18n.t('No')).replace(/</g, '&lt;').replace(/>/g, '&gt;');
    var labelClass = isYes ? yesClass : noClass;
    // Ensure we use Bootstrap label-* classes
    if (labelClass.indexOf('label-') !== 0) {
        labelClass = 'label-' + labelClass;
    }
    // Build HTML string directly (more reliable than mr.label() which has a bug in core)
    col.html('<span class="label ' + labelClass + '">' + labelText + '</span>');
}

var format_applecare_isRenewable = function(colNumber, row){
    var col = $('td:eq('+colNumber+')', row),
        value = col.text();
    // isRenewable: Yes = blue (info), No = yellow (warning)
    formatBooleanLabel(col, value, 'info', 'warning');
}

var format_applecare_isCanceled = function(colNumber, row){
    var col = $('td:eq('+colNumber+')', row),
        value = col.text();
    // isCanceled: Yes = red (danger), No = green (success)
    formatBooleanLabel(col, value, 'danger', 'success');
}

var format_applecare_enrolled_in_dep = function(colNumber, row){
    var col = $('td:eq('+colNumber+')', row),
        value = col.text();
    // enrolled_in_dep: Yes = green (success), No = red (danger)
    formatBooleanLabel(col, value, 'success', 'danger');
}

// Helper function to find end date in nearby columns
var findEndDateInRow = function(colNumber, row) {
    // Try known column offset first (endDateTime is typically 3 columns after status)
    var endDateCol = $('td:eq('+(colNumber+3)+')', row);
    if (endDateCol.length) {
        var endDateText = endDateCol.text();
        if (endDateText && (/^\d{4}-\d{2}-\d{2}/.test(endDateText) || moment(endDateText).isValid())) {
            return endDateText;
        }
    }
    
    // Search nearby columns if not found
    for (var i = colNumber + 1; i < colNumber + 6; i++) {
        var testCol = $('td:eq('+i+')', row);
        var testText = testCol.text();
        if (testText && (/^\d{4}-\d{2}-\d{2}/.test(testText) || moment(testText).isValid())) {
            return testText;
        }
    }
    return null;
}

// Helper function to parse date with fallbacks
var parseDateWithFallbacks = function(dateText) {
    if (!dateText) return null;
    
    // Try ISO format first (YYYY-MM-DD or YYYY-MM-DD HH:mm:ss)
    var parsedDate = /^\d{4}-\d{2}-\d{2}/.test(dateText) 
        ? moment(dateText, ['YYYY-MM-DD', 'YYYY-MM-DD HH:mm:ss'], true)
        : null;
    
    // Fallback to moment's automatic parsing
    if (!parsedDate || !parsedDate.isValid()) {
        parsedDate = moment(dateText);
    }
    
    return parsedDate.isValid() ? parsedDate : null;
}

var format_applecare_status = function(colNumber, row){
    var col = $('td:eq('+colNumber+')', row),
        status = col.text();
    var statusUpper = String(status).toUpperCase();
    var statusDisplay = status.charAt(0).toUpperCase() + status.slice(1).toLowerCase();
    
    if (statusUpper === 'ACTIVE') {
        // Try to get endDateTime from DataTables raw data first
        var oTable = $('.table').DataTable();
        var rowData = oTable.row(row).data();
        var endDateText = null;
        
        // endDateTime column index calculation:
        // status=colNumber, device_assignment_status=colNumber+1, description=colNumber+2, 
        // purchase_source_id=colNumber+3, startDateTime=colNumber+4, endDateTime=colNumber+5
        if (rowData && Array.isArray(rowData) && rowData[colNumber + 5]) {
            endDateText = rowData[colNumber + 5];
        } else {
            // Fallback: try to get from DOM
            var endDateCol = $('td:eq('+(colNumber+5)+')', row);
            if (endDateCol.length) {
                // Get the raw text first (before any formatting)
                endDateText = endDateCol.text();
                
                // If it's already formatted (has HTML), try to get from title attribute
                if (endDateCol.html() !== endDateText) {
                    var titleAttr = endDateCol.find('span[title]').attr('title');
                    if (titleAttr) {
                        // Title contains full date in 'llll' format, parse it
                        var titleDate = moment(titleAttr);
                        if (titleDate.isValid()) {
                            endDateText = titleDate.format('YYYY-MM-DD');
                        }
                    }
                }
            }
            
            // Final fallback to findEndDateInRow
            if (!endDateText || !endDateText.trim()) {
                endDateText = findEndDateInRow(colNumber, row);
            }
        }
        
        var labelClass = 'label-success';
        var tooltipText = '';
        var displayText = statusDisplay;
        
        // Check if end date is less than 31 days away
        if (endDateText && endDateText.trim()) {
            var parsedEndDate = parseDateWithFallbacks(endDateText);
            if (parsedEndDate && parsedEndDate.isValid()) {
                var daysUntil = parsedEndDate.diff(moment(), 'days');
                if (daysUntil >= 0 && daysUntil < 31) {
                    labelClass = 'label-warning';
                    displayText = (typeof i18n !== 'undefined' && i18n.t) ? i18n.t('applecare.expiring_soon') : 'Expiring Soon';
                    tooltipText = 'Coverage expires in ' + daysUntil + ' day' + (daysUntil !== 1 ? 's' : '');
                }
            }
        }
        
        // Build HTML string directly (more reliable than mr.label() which has a bug in core)
        var statusHtml = '<span class="label ' + labelClass + '"';
        if (tooltipText) {
            statusHtml += ' title="' + tooltipText.replace(/"/g, '&quot;') + '" data-toggle="tooltip"';
        }
        statusHtml += '>' + displayText.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</span>';
        col.html(statusHtml);
        if (tooltipText) {
            col.find('[data-toggle="tooltip"]').tooltip();
        }
    } else if (statusUpper === 'INACTIVE') {
        // Display as "Expired" but keep API value as "INACTIVE"
        // Build HTML string directly (same pattern as detail widget)
        var inactiveText = i18n.t('applecare.inactive').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        col.html('<span class="label label-danger">' + inactiveText + '</span>');
    } else {
        col.text(status);
    }
}

// Helper function to format date with locale-aware formatting
// Use mr.label() is not needed here as we're creating a simple span with tooltip
var formatDateWithLocale = function(dateText) {
    if (!dateText || !dateText.trim()) {
        return null;
    }
    
    var parsedDate = parseDateWithFallbacks(dateText);
    if (!parsedDate) {
        return null;
    }
    
    // Ensure locale is set (should be set in munkireport.js, but ensure it here too)
    if (typeof i18n !== 'undefined' && i18n.lng) {
        moment.locale(i18n.lng());
    }
    
    // Use 'll' format which is locale-aware short date (e.g., "Jan 29, 2022" for en, "29 jan. 2022" for fr)
    // This is more reliable than 'L' which might default to US format if locale isn't loaded
    return '<span title="' + parsedDate.format('llll') + '">' + parsedDate.format('ll') + '</span>';
}

var format_applecare_DateToMoment = function(colNumber, row){
    var col = $('td:eq('+colNumber+')', row),
        date = col.text();
    var formatted = formatDateWithLocale(date);
    if (formatted) {
        col.html(formatted);
    } else {
        col.text(date || '');
    }
}

var format_applecare_endDateTime = function(colNumber, row){
    var col = $('td:eq('+colNumber+')', row),
        date = col.text();
    var formatted = formatDateWithLocale(date);
    if (formatted) {
        col.html(formatted);
    } else {
        col.text(date || '');
    }
}

// Helper functions for boolean filters (reusable pattern)
var filter_boolean_yes = function(colNumber, d) {
    d.columns[colNumber].search.value = '= 1';
    d.search.value = '';
}

var filter_boolean_no = function(colNumber, d) {
    d.columns[colNumber].search.value = '= 0';
    d.search.value = '';
}

// Filter functions for column filters
var status_filter = function(colNumber, d) {
    if(d.search.value.match(/^active$/i)) {
        d.columns[colNumber].search.value = 'ACTIVE';
        d.search.value = '';
    }
    if(d.search.value.match(/^(inactive|expired)$/i)) {
        d.columns[colNumber].search.value = 'INACTIVE';
        d.search.value = '';
    }
}

var paymentType_filter = function(colNumber, d) {
    var paymentTypes = {
        'abe_subscription': 'ABE_SUBSCRIPTION',
        'paid_up_front': 'PAID_UP_FRONT',
        'subscription': 'SUBSCRIPTION',
        'none': 'NONE'
    };
    
    for (var key in paymentTypes) {
        if (d.search.value.match(new RegExp('^' + key + '$', 'i'))) {
            d.columns[colNumber].search.value = paymentTypes[key];
            d.search.value = '';
            break;
        }
    }
}

var isRenewable_filter = function(colNumber, d) {
    if(d.search.value.match(/^renewable_yes$/i)) {
        filter_boolean_yes(colNumber, d);
    }
    if(d.search.value.match(/^renewable_no$/i)) {
        filter_boolean_no(colNumber, d);
    }
}

var isCanceled_filter = function(colNumber, d) {
    if(d.search.value.match(/^canceled_yes$/i)) {
        filter_boolean_yes(colNumber, d);
    }
    if(d.search.value.match(/^canceled_no$/i)) {
        filter_boolean_no(colNumber, d);
    }
}

var format_applecare_device_assignment_status = function(colNumber, row) {
    var col = $('td:eq('+colNumber+')', row),
        status = col.text();
    
    if (!status || status.trim() === '') {
        col.html('<span class="label label-default">Unknown</span>');
        return;
    }
    
    var statusUpper = String(status).toUpperCase();
    
    // DEVICE_ASSIGNMENT_UNKNOWN with released_from_org_date should be displayed as Released
    // Since we can't easily check released_from_org_date in the formatter, we'll display
    // DEVICE_ASSIGNMENT_UNKNOWN as Released (matching widget behavior)
    if (statusUpper === 'DEVICE_ASSIGNMENT_UNKNOWN') {
        col.html('<span class="label label-danger">Released</span>');
        return;
    }
    
    var statusDisplay = status.charAt(0).toUpperCase() + status.slice(1).toLowerCase();
    
    if (statusUpper === 'ASSIGNED') {
        col.html('<span class="label label-success">' + statusDisplay.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</span>');
    } else if (statusUpper === 'UNASSIGNED') {
        col.html('<span class="label label-warning">' + statusDisplay.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</span>');
    } else if (statusUpper === 'RELEASED') {
        col.html('<span class="label label-danger">' + statusDisplay.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</span>');
    } else {
        col.text(statusDisplay);
    }
}

var device_assignment_status_filter = function(colNumber, d) {
    if(d.search.value.match(/^assigned$/i)) {
        d.columns[colNumber].search.value = 'ASSIGNED';
        d.columns[colNumber].search.regex = false;
        d.search.value = '';
    }
    if(d.search.value.match(/^unassigned$/i)) {
        d.columns[colNumber].search.value = 'UNASSIGNED';
        d.columns[colNumber].search.regex = false;
        d.search.value = '';
    }
    if(d.search.value.match(/^(released|DEVICE_ASSIGNMENT_UNKNOWN)$/i)) {
        // Released devices may have NULL or DEVICE_ASSIGNMENT_UNKNOWN in the database
        // We need to search for both, but DataTables can't do OR searches easily
        // So we'll search for DEVICE_ASSIGNMENT_UNKNOWN (which is what the API returns)
        d.columns[colNumber].search.value = 'DEVICE_ASSIGNMENT_UNKNOWN';
        d.columns[colNumber].search.regex = false;
        d.search.value = '';
    }
}

// Reseller ID to name mapping (loaded from server)
var resellerConfig = {};
var resellerConfigLoaded = false;

// Load reseller config on page load (load early, before appReady if possible)
(function() {
    $.getJSON(appUrl + '/module/applecare/get_reseller_config', function(data) {
        resellerConfig = data || {};
        resellerConfigLoaded = true;
    }).fail(function() {
        console.warn('AppleCare: Failed to load reseller config');
        resellerConfigLoaded = true; // Mark as loaded even on failure to prevent infinite waiting
    });
})();

// Also load on appReady as backup
$(document).on('appReady', function() {
    if (!resellerConfigLoaded) {
        $.getJSON(appUrl + '/module/applecare/get_reseller_config', function(data) {
            resellerConfig = data || {};
            resellerConfigLoaded = true;
        }).fail(function() {
            console.warn('AppleCare: Failed to load reseller config');
            resellerConfigLoaded = true;
        });
    }
});

// Export filter functions to global scope (required for YAML configs)
var format_applecare_reseller = function(colNumber, row) {
    var col = $('td:eq('+colNumber+')', row),
        resellerId = col.text();
    
    if (!resellerId || resellerId.trim() === '') {
        col.text('');
        return;
    }
    
    // Translate reseller ID to name using loaded config
    var resellerName = resellerConfig[resellerId];
    
    // Try case-insensitive match if exact match not found
    if (!resellerName) {
        var resellerIdUpper = resellerId.toUpperCase();
        for (var key in resellerConfig) {
            if (key.toUpperCase() === resellerIdUpper) {
                resellerName = resellerConfig[key];
                break;
            }
        }
    }
    
    // Display translated name if available, otherwise show ID
    if (resellerName && resellerName !== resellerId) {
        col.text(resellerName);
    } else {
        col.text(resellerId);
    }
}

var reseller_filter = function(colNumber, d) {
    // Only activate if search value matches a known reseller ID or name from the config
    // This prevents interfering with normal text searches
    if (!d.search.value || !d.search.value.trim()) {
        return;
    }
    
    var searchValue = d.search.value.trim();
    var matchedResellerId = null;
    
    // First check if search value is a reseller ID (case-insensitive)
    // This handles widget clicks which now use IDs in the hash
    for (var resellerId in resellerConfig) {
        if (resellerId.toLowerCase() === searchValue.toLowerCase()) {
            matchedResellerId = resellerId;
            break;
        }
    }
    
    // If not found as ID, check if it matches a reseller name (case-insensitive)
    // This handles manual searches by name
    if (!matchedResellerId) {
        for (var resellerId in resellerConfig) {
            var resellerName = resellerConfig[resellerId];
            if (resellerName && resellerName.toLowerCase() === searchValue.toLowerCase()) {
                matchedResellerId = resellerId;
                break;
            }
        }
    }
    
    // Only apply filter if we found a match
    if (matchedResellerId) {
        d.columns[colNumber].search.value = matchedResellerId;
        d.columns[colNumber].search.regex = false;
        d.search.value = '';
    }
    // Otherwise, let normal text search work
}

var enrolled_in_dep_filter = function(colNumber, d) {
    // Handle hash format (enrolled_in_dep=1 or enrolled_in_dep=0)
    if(d.search.value.match(/^enrolled_in_dep=1$/))
    {
        d.columns[colNumber].search.value = '= 1';
        d.columns[colNumber].search.regex = false;
        d.search.value = '';
    }
    if(d.search.value.match(/^enrolled_in_dep=0$/))
    {
        d.columns[colNumber].search.value = '= 0';
        d.columns[colNumber].search.regex = false;
        d.search.value = '';
    }
    // Also handle space format (enrolled_in_dep = 1 or enrolled_in_dep = 0)
    if(d.search.value.match(/^enrolled_in_dep = 1$/))
    {
        d.columns[colNumber].search.value = '= 1';
        d.columns[colNumber].search.regex = false;
        d.search.value = '';
    }
    if(d.search.value.match(/^enrolled_in_dep = 0$/))
    {
        d.columns[colNumber].search.value = '= 0';
        d.columns[colNumber].search.regex = false;
        d.search.value = '';
    }
}

// Export filter functions to global scope (required for YAML configs)
window.status_filter = status_filter;
window.paymentType_filter = paymentType_filter;
window.isRenewable_filter = isRenewable_filter;
window.isCanceled_filter = isCanceled_filter;
window.device_assignment_status_filter = device_assignment_status_filter;
window.reseller_filter = reseller_filter;
window.enrolled_in_dep_filter = enrolled_in_dep_filter;

// Parse hash parameters for filtering
var applecareHashParams = {};
var hashFromHashChange = false; // Track if hash came from hashchange event (widget click)

function parseApplecareHash() {
    applecareHashParams = {};
    var hash = window.location.hash.substring(1);
    if (hash) {
        // Decode the entire hash first (button widget encodes the whole search_component)
        hash = decodeURIComponent(hash);
        hash.split('&').forEach(function(param) {
            var parts = param.split('=');
            if (parts.length === 2) {
                applecareHashParams[parts[0]] = decodeURIComponent(parts[1]);
            }
        });
    }
}

// Clear search state when navigating to the listing without a hash
// This prevents stale widget searches from persisting between navigations
function clearApplecareListingSearch() {
    var clearTable = function() {
        try {
            if ($.fn.dataTable.isDataTable('.table')) {
                var oTable = $('.table').DataTable();
                if (oTable) {
                    // Clear global and column searches
                    oTable.search('');
                    if (oTable.columns) {
                        oTable.columns().search('');
                    }
                    // Clear search input
                    var searchInput = $('.dataTables_filter input');
                    if (searchInput.length > 0) {
                        searchInput.val('');
                    }
                    // Reload to apply cleared filters
                    if (oTable.ajax && typeof oTable.ajax.reload === 'function') {
                        oTable.ajax.reload();
                    }
                }
                return true;
            }
        } catch (e) {
            // Ignore and retry
        }
        return false;
    };

    // If DataTable is ready, clear immediately; otherwise retry briefly
    if (!clearTable()) {
        var attempts = 0;
        var checkTable = setInterval(function() {
            attempts += 1;
            if (clearTable() || attempts > 20) {
                clearInterval(checkTable);
            }
        }, 100);
    }
}

// Function to wrap mr.listingFilter.filter()
function wrapApplecareFilter() {
    if (typeof mr !== 'undefined' && mr.listingFilter && mr.listingFilter.filter && !mr.listingFilter.filter._applecareWrapped) {
        var originalFilter = mr.listingFilter.filter;
        mr.listingFilter.filter = function(d, columnFilters) {
            // Re-parse hash in case it changed
            parseApplecareHash();
            
            // Handle scrollbox widget hash (just a label value, not key=value)
            // If hash exists but no key=value pairs were parsed, treat hash as search value
            // Only apply if hash looks like a widget hash (short, no spaces)
            var hash = window.location.hash.substring(1);
            if (hash && Object.keys(applecareHashParams).length === 0) {
                try {
                    var decodedHash = decodeURIComponent(hash);
                    // Only apply if it looks like a widget hash (short, no spaces)
                    // This prevents applying manual search hashes
                    if (decodedHash.length > 0 && decodedHash.length <= 100 && decodedHash.indexOf(' ') === -1) {
                        // This is a scrollbox widget hash - decode it and set as search value
                        // Only set if it's not already set (to allow manual searches)
                        if (!d.search.value || d.search.value.trim() === '') {
                            d.search.value = decodedHash;
                        }
                    }
                } catch(e) {
                    // If decoding fails, ignore it
                }
            }
            
            // Check if global search matches a reseller name and convert to ID search
            // Only do this if resellerConfig is loaded and has data
            if (d.search.value && d.search.value.trim() && resellerConfigLoaded && resellerConfig && Object.keys(resellerConfig).length > 0) {
                var searchValue = d.search.value.trim();
                var matchedResellerId = null;
                
                // First check for exact name match (case-insensitive) - prioritize exact matches
                for (var resellerId in resellerConfig) {
                    var resellerName = resellerConfig[resellerId];
                    if (resellerName && resellerName.toLowerCase() === searchValue.toLowerCase()) {
                        matchedResellerId = resellerId;
                        break;
                    }
                }
                
                // Then check for exact ID match
                if (!matchedResellerId) {
                    for (var resellerId in resellerConfig) {
                        if (resellerId.toLowerCase() === searchValue.toLowerCase()) {
                            matchedResellerId = resellerId;
                            break;
                        }
                    }
                }
                
                // Finally check for partial name match (only if no exact match found)
                if (!matchedResellerId) {
                    for (var resellerId in resellerConfig) {
                        var resellerName = resellerConfig[resellerId];
                        if (resellerName && resellerName.toLowerCase().indexOf(searchValue.toLowerCase()) !== -1) {
                            matchedResellerId = resellerId;
                            break;
                        }
                    }
                }
                
                // If we found a match, search the reseller column by ID
                if (matchedResellerId) {
                    // First try to find column index from columnFilters (most reliable)
                    var resellerColumnIndex = null;
                    if (columnFilters) {
                        for (var j = 0; j < columnFilters.length; j++) {
                            if (columnFilters[j].filter === 'reseller_filter') {
                                resellerColumnIndex = columnFilters[j].column;
                                break;
                            }
                        }
                    }
                    
                    // Fallback: find by column name
                    if (resellerColumnIndex === null) {
                        for (var i = 0; i < d.columns.length; i++) {
                            var colName = d.columns[i].name || d.columns[i].data;
                            if (colName && (colName === 'applecare.purchase_source_id' || colName.indexOf('purchase_source_id') !== -1)) {
                                resellerColumnIndex = i;
                                break;
                            }
                        }
                    }
                    
                    // If we found the column, set the search value
                    if (resellerColumnIndex !== null && d.columns[resellerColumnIndex]) {
                        d.columns[resellerColumnIndex].search.value = matchedResellerId;
                        d.columns[resellerColumnIndex].search.regex = false;
                        d.search.value = ''; // Clear global search
                    }
                }
            }
            
            // Call original filter first (handles columnFilters from YAML)
            originalFilter.call(this, d, columnFilters);
            
            // Apply hash filters - use where clause for date filtering
            // Initialize where as array if needed
            if (!d.where || (typeof d.where === 'string' && d.where === '')) {
                d.where = [];
            } else if (!Array.isArray(d.where)) {
                d.where = [];
            }
        
        // Apply hash filters using where clause (supports date comparisons)
        if (applecareHashParams.expired === '1') {
            // Expired: endDateTime <= today (end date is in the past or today)
            // This matches the controller logic: $endDate <= $now
            // Use < tomorrow 00:00:00 to include today
            var tomorrow = moment().add(1, 'days').format('YYYY-MM-DD');
            var expiredValue = tomorrow + ' 00:00:00';
            d.where.push({
                table: 'applecare',
                column: 'endDateTime',
                operator: '<',
                value: expiredValue
            });
        }
        
        if (applecareHashParams.expiring === '1') {
            // Expiring soon: endDateTime >= today AND endDateTime <= 30 days from now AND status = ACTIVE
            // >= today means > yesterday 23:59:59
            var yesterday = moment().subtract(1, 'days').format('YYYY-MM-DD');
            d.where.push({
                table: 'applecare',
                column: 'endDateTime',
                operator: '>',
                value: yesterday + ' 23:59:59'
            });
            
            // <= 30 days means < day after 30 days
            var dayAfter = moment().add(31, 'days').format('YYYY-MM-DD');
            d.where.push({
                table: 'applecare',
                column: 'endDateTime',
                operator: '<',
                value: dayAfter + ' 00:00:00'
            });
            
            // Also filter by status = ACTIVE (exact match, no regex)
            $.each(d.columns, function(index, item){
                if(item.name === 'applecare.status'){
                    d.columns[index].search.value = 'ACTIVE';
                    d.columns[index].search.regex = false;
                }
            });
        }
        
        if (applecareHashParams.status) {
            var found = false;
            $.each(d.columns, function(index, item){
                if(item.name === 'applecare.status'){
                    d.columns[index].search.value = applecareHashParams.status; // Exact match, no regex
                    d.columns[index].search.regex = false;
                    found = true;
                }
            });
            if (found) {
                // Clear global search when column search is set
                d.search.value = '';
            }
        }
        
        if (applecareHashParams.isRenewable !== undefined && applecareHashParams.isRenewable !== null) {
            var found = false;
            $.each(d.columns, function(index, item){
                if(item.name === 'applecare.isRenewable'){
                    // Use = 1 or = 0 format like other modules (boolean fields)
                    d.columns[index].search.value = '= ' + applecareHashParams.isRenewable;
                    d.columns[index].search.regex = false;
                    found = true;
                }
            });
            if (found) {
                // Clear global search when column search is set
                d.search.value = '';
            }
        }
        
        if (applecareHashParams.isCanceled !== undefined && applecareHashParams.isCanceled !== null) {
            var found = false;
            $.each(d.columns, function(index, item){
                if(item.name === 'applecare.isCanceled'){
                    // Use = 1 or = 0 format like other modules (boolean fields)
                    d.columns[index].search.value = '= ' + applecareHashParams.isCanceled;
                    d.columns[index].search.regex = false;
                    found = true;
                }
            });
            if (found) {
                // Clear global search when column search is set
                d.search.value = '';
            }
        }
        
        if (applecareHashParams.enrolled_in_dep !== undefined && applecareHashParams.enrolled_in_dep !== null) {
            var found = false;
            $.each(d.columns, function(index, item){
                if(item.name === 'mdm_status.enrolled_in_dep'){
                    // Use = 1 or = 0 format like other modules (boolean fields)
                    d.columns[index].search.value = '= ' + applecareHashParams.enrolled_in_dep;
                    d.columns[index].search.regex = false;
                    found = true;
                }
            });
            if (found) {
                // Clear global search when column search is set
                d.search.value = '';
            }
        }
        };
        // Mark as wrapped to prevent multiple wraps
        mr.listingFilter.filter._applecareWrapped = true;
    }
}

// Try to wrap immediately if mr is available
wrapApplecareFilter();

// Also try to wrap when document is ready (in case mr loads later)
$(document).ready(function() {
    wrapApplecareFilter();
});

// Handle hash on initial page load and hash changes
$(document).on('appReady', function(e, lang) {
    // Parse hash (if present)
    parseApplecareHash();

    var hash = window.location.hash.substring(1);
    var referrer = document.referrer || '';
    var fromApplecareListing = referrer.indexOf('/show/listing/applecare') !== -1;

    // If we're coming from the AppleCare listing and a hash is still present,
    // treat it as stale and clear it to avoid persisting widget filters
    if (hash && fromApplecareListing) {
        history.replaceState(null, null, window.location.pathname);
        applecareHashParams = {};
        clearApplecareListingSearch();
        return;
    }

    // If no hash, clear any stale search state
    if (!hash) {
        applecareHashParams = {};
        clearApplecareListingSearch();
        return;
    }

    // Apply hash filters if hash is present
    if ($('.table').length > 0 && Object.keys(applecareHashParams).length > 0) {
        // Wait for DataTable to be fully initialized
        var checkTable = setInterval(function() {
            try {
                if ($.fn.dataTable.isDataTable('.table')) {
                    var oTable = $('.table').DataTable();
                    if (oTable && oTable.ajax) {
                        clearInterval(checkTable);
                        // Small delay to ensure everything is ready
                        setTimeout(function() {
                            oTable.ajax.reload();
                        }, 100);
                    }
                }
            } catch(e) {
                // DataTable not ready yet, continue checking
            }
        }, 100);

        // Stop checking after 5 seconds
        setTimeout(function() {
            clearInterval(checkTable);
        }, 5000);
    }
});

// Handle hash changes - reload table when hash changes (when already on listing page)
// This is triggered by widget clicks, so we preserve and apply the hash
$(window).on('hashchange', function() {
    hashFromHashChange = true; // Mark that hash came from hashchange event
    parseApplecareHash();
    // Only reload if we're on the listing page
    if ($('.table').length > 0) {
        // Wait for DataTable to be fully initialized before reloading
        var checkTable = setInterval(function() {
            try {
                // Check if DataTable is already initialized
                if ($.fn.dataTable.isDataTable('.table')) {
                    var oTable = $('.table').DataTable();
                    // Check if DataTable has ajax method
                    if (oTable && typeof oTable.ajax === 'function' && typeof oTable.ajax.reload === 'function') {
                        clearInterval(checkTable);
                        // Small delay to ensure everything is ready
                        setTimeout(function() {
                            try {
                                oTable.ajax.reload();
                            } catch(e) {
                                // DataTable might not be ready yet, ignore error
                            }
                        }, 100);
                    }
                }
            } catch(e) {
                // DataTable not initialized yet, continue checking
            }
        }, 100);
        
        // Stop checking after 5 seconds
        setTimeout(function() {
            clearInterval(checkTable);
        }, 5000);
    }
});
