<div id="applecare-tab"></div>
<h2><i class="fa fa-medkit"></i> <span data-i18n="applecare.title"></span></h2>

<div style="margin-bottom: 15px;">
    <button id="applecare-sync-btn" class="btn btn-default btn-sm">
        <i class="fa fa-refresh"></i> <span data-i18n="applecare.sync_now">Sync Now</span>
    </button>
    <span id="applecare_last_fetched" style="color: #bbb; margin-left: 10px; font-size: 10px;"></span>
</div>

<div id="applecare-info-section" style="margin-bottom: 30px;">
    <h3 data-i18n="applecare.coverage_info.title">AppleCare Coverage</h3>
    <table id="applecare-tab-table" style="max-width:475px;"><tbody></tbody></table>
</div>

<div id="device-info-section">
    <h3 data-i18n="applecare.device_info.title">AxM Device Information</h3>
    <table id="device-info-table" style="max-width:475px;"><tbody></tbody></table>
</div>

<div id="applecare-sync-message" style="margin-top: 10px;"></div>

<script>
$(document).on('appReady', function(){
    // Helper function to parse date with multiple fallback formats
    var parseDate = function(dateValue) {
        if (!dateValue) return null;
        
        var parsedDate = null;
        
        // Check if it's a numeric timestamp (Unix seconds)
        if (/^\d+$/.test(String(dateValue))) {
            parsedDate = moment.unix(parseInt(dateValue));
        }
        // Try ISO format first (YYYY-MM-DD or YYYY-MM-DD HH:mm:ss)
        else if (/^\d{4}-\d{2}-\d{2}/.test(dateValue)) {
            parsedDate = moment(dateValue, ['YYYY-MM-DD', 'YYYY-MM-DD HH:mm:ss'], true);
        }
        
        // Fallback to general moment parsing
        if (!parsedDate || !parsedDate.isValid()) {
            parsedDate = moment(dateValue);
        }
        
        return parsedDate.isValid() ? parsedDate : null;
    };
    
    // Helper function to format date with locale-aware formatting
    var formatDate = function(dateValue) {
        var parsedDate = parseDate(dateValue);
        if (!parsedDate) return null;
        
        // Ensure locale is set
        if (typeof i18n !== 'undefined' && i18n.lng) {
            moment.locale(i18n.lng());
        }
        
        return '<span title="' + parsedDate.format('llll') + '">' + parsedDate.format('ll') + '</span>';
    };
    
    // Function to format MAC address as xx:xx:xx:xx:xx:xx
    var formatMacAddress = function(mac) {
        if (!mac) return '';
        // Remove any existing colons or spaces
        mac = mac.replace(/[:\s-]/g, '').toUpperCase();
        // Add colons every 2 characters
        return mac.match(/.{1,2}/g).join(':');
    };
    
    // Function to format boolean value
    var formatBoolean = function(value, yesClass, noClass) {
        var isYes = (value == '1' || value == 'true' || value === true || String(value).toLowerCase() === 'true');
        var displayText = isYes ? i18n.t('Yes') : i18n.t('No');
        var labelClass = isYes ? yesClass : noClass;
        return '<span class="label ' + labelClass + '">' + displayText + '</span>';
    };
    
    var loadAppleCareData = function() {
        $.getJSON(appUrl + '/module/applecare/get_data/' + serialNumber)
            .done(function(data){
                // Update last fetched timestamp in header (similar to jamf and mosyle)
                if (data && data.last_fetched !== null && data.last_fetched !== undefined) {
                    var lastFetched = parseDate(data.last_fetched);
                    if (lastFetched) {
                        $('#applecare_last_fetched').html(i18n.t('applecare.column.last_fetched') + ': ' + lastFetched.fromNow());
                    }
                }
                // Separate device info fields from AppleCare fields
                var deviceInfoFields = ['model', 'part_number', 'product_type', 'color', 'device_assignment_status', 'mdm_server', 'enrolled_in_dep', 'purchase_source_type', 'purchase_source_name', 'purchase_source_id', 'order_number', 'order_date', 'added_to_org_date', 'released_from_org_date', 'wifi_mac_address', 'ethernet_mac_address', 'bluetooth_mac_address'];
                var applecareFields = ['status', 'description', 'startDateTime', 'endDateTime', 'paymentType', 'isRenewable', 'isCanceled', 'contractCancelDateTime', 'agreementNumber', 'last_updated'];
            
            // Device Information Table
            var deviceTable = $('#device-info-table');
            deviceTable.find('tbody').empty();
            
            var hasDeviceInfo = false;
            deviceInfoFields.forEach(function(key) {
                if (data[key] !== null && data[key] !== undefined && data[key] !== '') {
                    hasDeviceInfo = true;
                    var th = $('<th>').text(i18n.t('applecare.column.' + key));
                    var td = $('<td>');
                    
                    // Format activation lock status
                    if (key === 'activation_lock_status') {
                        var statusUpper = String(data[key]).toUpperCase();
                        if (statusUpper === 'ENABLED' || statusUpper === 'ACTIVE' || statusUpper === 'TRUE') {
                            td.html('<span class="label label-danger">' + data[key] + '</span>');
                        } else if (statusUpper === 'DISABLED' || statusUpper === 'INACTIVE' || statusUpper === 'FALSE') {
                            td.html('<span class="label label-success">' + data[key] + '</span>');
                        } else {
                            td.text(data[key]);
                        }
                    }
                    // Format device assignment status
                    else if (key === 'device_assignment_status') {
                        if (!data[key] || data[key] === null || data[key] === '') {
                            // If we have released_from_org_date, assume it's released
                            if (data['released_from_org_date']) {
                                td.html('<span class="label label-danger">Released</span>');
                            } else {
                                td.html('<span class="label label-default">Unknown</span>');
                            }
                        } else {
                            var statusUpper = String(data[key]).toUpperCase();
                            var statusDisplay = data[key].charAt(0).toUpperCase() + data[key].slice(1).toLowerCase();
                            if (statusUpper === 'ASSIGNED') {
                                td.html('<span class="label label-success">' + statusDisplay + '</span>');
                            } else if (statusUpper === 'UNASSIGNED') {
                                td.html('<span class="label label-warning">' + statusDisplay + '</span>');
                            } else if (statusUpper === 'RELEASED') {
                                td.html('<span class="label label-danger">' + statusDisplay + '</span>');
                            } else {
                                // Handle other statuses - if we have released_from_org_date, show Released
                                if (data['released_from_org_date']) {
                                    td.html('<span class="label label-danger">Released</span>');
                                } else {
                                    td.text(statusDisplay);
                                }
                            }
                        }
                    }
                    // Format enrolled_in_dep
                    else if (key === 'enrolled_in_dep') {
                        td.html(formatBoolean(data[key], 'label-success', 'label-danger'));
                    }
                    // Format purchase source type
                    else if (key === 'purchase_source_type') {
                        var sourceTypeDisplay = {
                            'MANUALLY_ADDED': 'Manually Added',
                            'RESELLER': 'Reseller',
                            'DIRECT': 'Direct',
                            'UNKNOWN': 'Unknown'
                        };
                        var typeUpper = String(data[key]).toUpperCase();
                        var displayValue = sourceTypeDisplay[typeUpper] || data[key].replace(/_/g, ' ').replace(/\b\w/g, function(l) { return l.toUpperCase(); });
                        td.text(displayValue);
                    }
                    // Format purchase source name with ID in faded brackets
                    else if (key === 'purchase_source_name') {
                        var resellerName = data[key];
                        var resellerId = data['purchase_source_id_display'] || data['purchase_source_id'];
                        if (resellerName && resellerId && resellerName !== resellerId) {
                            // Show name with ID in faded brackets
                            td.html(resellerName + ' <span class="text-muted">(' + resellerId + ')</span>');
                        } else if (resellerName) {
                            td.text(resellerName);
                        } else {
                            // No translation found, don't show this field (will show purchase_source_id instead)
                            return; // Skip adding this row
                        }
                    }
                    // Format purchase source ID (only show if no name translation exists)
                    else if (key === 'purchase_source_id') {
                        // Only show if purchase_source_name doesn't exist (no translation found)
                        if (data['purchase_source_name']) {
                            return; // Skip adding this row, purchase_source_name already shown it
                        }
                        td.text(data[key]);
                    }
                    // Format dates using helper function
                    else if (key === 'order_date' || key === 'added_to_org_date' || key === 'released_from_org_date') {
                        var formatted = formatDate(data[key]);
                        if (formatted) {
                            td.html(formatted);
                        } else {
                            td.text(data[key] || '');
                        }
                    }
                    // Format MAC addresses
                    else if (key === 'wifi_mac_address' || key === 'ethernet_mac_address' || key === 'bluetooth_mac_address') {
                        if (data[key]) {
                            // Handle comma-separated MAC addresses (for ethernet)
                            var macs = String(data[key]).split(',').map(function(m) { return m.trim(); });
                            var formattedMacs = macs.map(formatMacAddress).filter(function(m) { return m; });
                            td.text(formattedMacs.join(', '));
                        } else {
                            td.text('');
                        }
                    }
                    // Format color - convert to title case (e.g., "SPACE BLACK" -> "Space Black")
                    else if (key === 'color') {
                        var colorValue = String(data[key]);
                        // Convert to title case: lowercase first, then capitalize first letter of each word
                        var titleCase = colorValue.toLowerCase().replace(/\b\w/g, function(l) { return l.toUpperCase(); });
                        td.text(titleCase);
                    }
                    // Format MDM enrollment status
                    else if (key === 'mdm_enrollment_status') {
                        var statusUpper = String(data[key]).toUpperCase();
                        if (statusUpper === 'ENROLLED' || statusUpper === 'YES') {
                            td.html('<span class="label label-success">' + data[key] + '</span>');
                        } else if (statusUpper === 'NOT_ENROLLED' || statusUpper === 'NO') {
                            td.html('<span class="label label-warning">' + data[key] + '</span>');
                        } else {
                            td.text(data[key]);
                        }
                    }
                    else {
                        td.text(data[key]);
                    }
                    
                    deviceTable.find('tbody').append($('<tr>').append(th, td));
                }
            });
            
            if (!hasDeviceInfo) {
                deviceTable.find('tbody').append(
                    $('<tr>').append(
                        $('<td>')
                            .attr('colspan', 2)
                            .addClass('text-muted')
                            .text(i18n.t('no_data'))
                    )
                );
            }
            
            // AppleCare Coverage Table
            var applecareTable = $('#applecare-tab-table');
            applecareTable.find('tbody').empty();
            
            var hasApplecareInfo = false;
            applecareFields.forEach(function(key) {
                if (data[key] !== null && data[key] !== undefined && data[key] !== '') {
                    hasApplecareInfo = true;
                    var th = $('<th>').text(i18n.t('applecare.column.' + key));
                    var td = $('<td>');
                    
                    // Format status with colors (capitalize first letter only)
                    // Check end date for expired/expiring status
                    if (key === 'status') {
                        var statusUpper = String(data[key]).toUpperCase();
                        var statusDisplay = data[key].charAt(0).toUpperCase() + data[key].slice(1).toLowerCase();
                        var labelClass = '';
                        
                        if (statusUpper === 'ACTIVE') {
                            var tooltipText = '';
                            labelClass = 'label-success';
                            
                            // Check if endDateTime exists
                            if (data.endDateTime) {
                                var parsedEndDate = moment(data.endDateTime, ['YYYY-MM-DD', 'YYYY-MM-DD HH:mm:ss'], true);
                                if (parsedEndDate.isValid()) {
                                    var now = moment().startOf('day');
                                    var daysUntil = parsedEndDate.diff(now, 'days');
                                    
                                    if (daysUntil < 0) {
                                        // End date is in the past - coverage has expired
                                        labelClass = 'label-danger';
                                        statusDisplay = i18n.t('applecare.expired') || 'Expired';
                                        var daysAgo = Math.abs(daysUntil);
                                        tooltipText = 'Coverage expired ' + daysAgo + ' day' + (daysAgo !== 1 ? 's' : '') + ' ago';
                                    } else if (daysUntil < 31) {
                                        // Expiring within 30 days
                                        labelClass = 'label-warning';
                                        tooltipText = 'Coverage expires in ' + daysUntil + ' day' + (daysUntil !== 1 ? 's' : '');
                                    }
                                }
                            }
                            var statusHtml = '<span class="label ' + labelClass + '"';
                            if (tooltipText) {
                                statusHtml += ' title="' + tooltipText + '" data-toggle="tooltip"';
                            }
                            statusHtml += '>' + statusDisplay + '</span>';
                            td.html(statusHtml);
                            
                            // Initialize tooltip if present
                            if (tooltipText) {
                                td.find('[data-toggle="tooltip"]').tooltip();
                            }
                        } else if (statusUpper === 'INACTIVE') {
                            td.html('<span class="label label-danger">' + i18n.t('applecare.inactive') + '</span>');
                        } else {
                            td.text(data[key]);
                        }
                    }
                    // Format boolean fields as Yes/No with appropriate classes
                    else if (key === 'isRenewable') {
                        td.html(formatBoolean(data[key], 'label-info', 'label-warning'));
                    }
                    else if (key === 'isCanceled') {
                        td.html(formatBoolean(data[key], 'label-danger', 'label-success'));
                    }
                    // Format payment type
                    else if (key === 'paymentType') {
                        var paymentTypeDisplay = {
                            'ABE_SUBSCRIPTION': 'ABE Subscription',
                            'PAID_UP_FRONT': 'Paid Up Front',
                            'SUBSCRIPTION': 'Subscription',
                            'NONE': 'None'
                        };
                        var paymentUpper = String(data[key]).toUpperCase();
                        var displayValue = paymentTypeDisplay[paymentUpper] || data[key];
                        td.text(displayValue);
                    }
                    // Format dates using helper function
                    else if (key === 'startDateTime' || key === 'contractCancelDateTime' || key === 'endDateTime' || key === 'last_updated') {
                        var formatted = formatDate(data[key]);
                        if (formatted) {
                            td.html(formatted);
                        } else {
                            td.text(data[key] || '');
                        }
                    }
                    else {
                        td.text(data[key]);
                    }
                    
                    applecareTable.find('tbody').append($('<tr>').append(th, td));
                }
            });
            
            if (!hasApplecareInfo) {
                applecareTable.find('tbody').append(
                    $('<tr>').append(
                        $('<td>')
                            .attr('colspan', 2)
                            .addClass('text-muted')
                            .text(i18n.t('no_data'))
                    )
                );
            }
            })
            .fail(function(xhr, textStatus, errorThrown) {
                // Handle errors gracefully
                var errorMsg = i18n.t('error_loading_data') || 'Error loading AppleCare data';
                if (xhr.status === 404) {
                    errorMsg = i18n.t('no_data') || 'No AppleCare data available';
                } else if (xhr.status === 0) {
                    errorMsg = 'Network error - please check your connection';
                } else if (xhr.status >= 500) {
                    errorMsg = 'Server error - please try again later';
                }
                
                // Show error in both tables
                $('#device-info-table tbody').html(
                    '<tr><td colspan="2" class="text-danger">' + errorMsg + '</td></tr>'
                );
                $('#applecare-tab-table tbody').html(
                    '<tr><td colspan="2" class="text-danger">' + errorMsg + '</td></tr>'
                );
            });
    };

    // Load initial data
    loadAppleCareData();

    // Handle sync button click
    $('#applecare-sync-btn').on('click', function() {
        var $btn = $(this);
        var $message = $('#applecare-sync-message');

        // Disable button and show loading state
        $btn.prop('disabled', true);
        $btn.find('i').addClass('fa-spin');
        $message.removeClass('alert-success alert-danger').addClass('alert alert-info').html('<i class="fa fa-spinner fa-spin"></i> Syncing AppleCare data for ' + serialNumber + '...').show();
        
        $.ajax({
            url: appUrl + '/module/applecare/sync_serial/' + serialNumber,
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                $btn.prop('disabled', false);
                $btn.find('i').removeClass('fa-spin');

                if (response.success) {
                    $message.removeClass('alert-info').addClass('alert-success').html('<i class="fa fa-check"></i> ' + (response.message || 'Sync completed successfully'));

                    // Reload the data
                    setTimeout(function() {
                        loadAppleCareData();
                        $message.fadeOut();
                    }, 1000);
                } else {
                    $message.removeClass('alert-info').addClass('alert-danger').html('<i class="fa fa-exclamation-triangle"></i> ' + (response.message || 'Sync failed'));
                }
            },
            error: function(xhr) {
                $btn.prop('disabled', false);
                $btn.find('i').removeClass('fa-spin');

                var errorMsg = 'Sync failed';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMsg = xhr.responseJSON.message;
                } else if (xhr.status === 0) {
                    errorMsg = 'Network error - please check your connection';
                } else {
                    errorMsg = 'Sync failed: HTTP ' + xhr.status;
                }

                $message.removeClass('alert-info').addClass('alert-danger').html('<i class="fa fa-exclamation-triangle"></i> ' + errorMsg);
            }
        });
    });
});
</script>
