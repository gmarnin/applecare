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
        var endDateText = findEndDateInRow(colNumber, row);
        var labelClass = 'label-success';
        var tooltipText = '';
        
        // Check if end date is less than 31 days away
        if (endDateText) {
            var parsedEndDate = parseDateWithFallbacks(endDateText);
            if (parsedEndDate) {
                var daysUntil = parsedEndDate.diff(moment(), 'days');
                if (daysUntil >= 0 && daysUntil < 31) {
                    labelClass = 'label-warning';
                    tooltipText = 'Coverage expires in ' + daysUntil + ' day' + (daysUntil !== 1 ? 's' : '');
                }
            }
        }
        
        // Build HTML string directly (more reliable than mr.label() which has a bug in core)
        var statusHtml = '<span class="label ' + labelClass + '"';
        if (tooltipText) {
            statusHtml += ' title="' + tooltipText.replace(/"/g, '&quot;') + '" data-toggle="tooltip"';
        }
        statusHtml += '>' + statusDisplay.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</span>';
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

// Export filter functions to global scope (required for YAML configs)
window.status_filter = status_filter;
window.paymentType_filter = paymentType_filter;
window.isRenewable_filter = isRenewable_filter;
window.isCanceled_filter = isCanceled_filter;

// Parse hash parameters for filtering
var applecareHashParams = {};
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
// Parse hash immediately (in case script loads after page)
parseApplecareHash();

// Function to wrap mr.listingFilter.filter()
function wrapApplecareFilter() {
    if (typeof mr !== 'undefined' && mr.listingFilter && mr.listingFilter.filter && !mr.listingFilter.filter._applecareWrapped) {
        var originalFilter = mr.listingFilter.filter;
        mr.listingFilter.filter = function(d, columnFilters) {
            // Call original filter first (handles columnFilters from YAML)
            originalFilter.call(this, d, columnFilters);
            
            // Re-parse hash in case it changed
            parseApplecareHash();
            
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
    // Process hash on initial page load
    parseApplecareHash();
    
    // If we're on the listing page and have a hash, wait for DataTable to initialize then reload
    if ($('.table').length > 0 && Object.keys(applecareHashParams).length > 0) {
        // Wait for DataTable to be fully initialized
        var checkTable = setInterval(function() {
            var oTable = $('.table').DataTable();
            if (oTable && oTable.ajax) {
                clearInterval(checkTable);
                // Small delay to ensure everything is ready
                setTimeout(function() {
                    oTable.ajax.reload();
                }, 100);
            }
        }, 100);
        
        // Stop checking after 5 seconds
        setTimeout(function() {
            clearInterval(checkTable);
        }, 5000);
    }
});

// Handle hash changes - reload table when hash changes (when already on listing page)
$(window).on('hashchange', function() {
    parseApplecareHash();
    // Only reload if we're on the listing page
    if ($('.table').length > 0) {
        var oTable = $('.table').DataTable();
        if (oTable) {
            oTable.ajax.reload();
        }
    }
});
