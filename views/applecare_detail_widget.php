<div class="col-lg-4">
    <h4><i class="fa fa-medkit"></i> <span data-i18n="applecare.title"></span><a data-toggle="tab" title="AppleCare" class="btn btn-xs pull-right" href="#applecare" aria-expanded="false"><i class="fa fa-arrow-right"></i></a></h4>
    <table id="applecare_status-data" class="table"></table>
</div>


<script>
$(document).on('appReady', function () {

    $.getJSON(appUrl + '/module/applecare/get_data/' + serialNumber, function (data) {

        var table = $('#applecare_status-data');
        table.empty();

        // Handle missing data gracefully
        if (!data || $.isEmptyObject(data)) {
            table.append(
                $('<tbody>').append(
                    $('<tr>').append(
                        $('<td>')
                            .attr('colspan', 2)
                            .addClass('text-muted')
                            .text(i18n.t('no_data'))
                    )
                )
            );
            return;
        }

        var rows = [
            { label: i18n.t('applecare.column.status'), value: data.status, type: 'status' },
            { label: i18n.t('applecare.column.description'), value: data.description },
            { label: i18n.t('applecare.column.endDateTime'), value: data.endDateTime, type: 'date' },
            { label: i18n.t('applecare.column.isRenewable'), value: data.isRenewable, type: 'isRenewable' },
            { label: i18n.t('applecare.column.isCanceled'), value: data.isCanceled, type: 'isCanceled' }
        ];

        var tbody = $('<tbody>');
        rows.forEach(function (row) {
            if (row.value !== null && row.value !== undefined && row.value !== '') {
                var td = $('<td>');
                
                // Format status with colors (capitalize first letter only)
                // Check end date for 31-day warning
                if (row.type === 'status') {
                    var statusUpper = String(row.value).toUpperCase();
                    var statusDisplay = row.value.charAt(0).toUpperCase() + row.value.slice(1).toLowerCase();
                    var labelClass = '';
                    
                    if (statusUpper === 'ACTIVE') {
                        // Check if endDateTime exists and is less than 31 days away
                        var endDateRow = rows.find(function(r) { return r.label && r.label.indexOf('endDateTime') !== -1; });
                        if (endDateRow && endDateRow.value) {
                            var parsedEndDate = moment(endDateRow.value, ['YYYY-MM-DD', 'YYYY-MM-DD HH:mm:ss'], true);
                            if (parsedEndDate.isValid()) {
                                var now = moment();
                                var daysUntil = parsedEndDate.diff(now, 'days');
                                // If end date is less than 31 days away, use yellow warning
                                if (daysUntil >= 0 && daysUntil < 31) {
                                    labelClass = 'label-warning';
                                } else {
                                    labelClass = 'label-success';
                                }
                            } else {
                                labelClass = 'label-success';
                            }
                        } else {
                            labelClass = 'label-success';
                        }
                        td.html('<span class="label ' + labelClass + '">' + statusDisplay + '</span>');
                    } else if (statusUpper === 'INACTIVE') {
                        td.html('<span class="label label-danger">' + i18n.t('applecare.inactive') + '</span>');
                    } else {
                        td.text(row.value);
                    }
                }
                // Format dates using moment.js with locale
                else if (row.type === 'date') {
                    if (row.value) {
                        var parsedDate = null;
                        
                        // Try ISO format first (YYYY-MM-DD or YYYY-MM-DD HH:mm:ss) - this is what the model returns
                        if (/^\d{4}-\d{2}-\d{2}/.test(row.value)) {
                            parsedDate = moment(row.value, ['YYYY-MM-DD', 'YYYY-MM-DD HH:mm:ss'], true);
                        }
                        
                        // If ISO format didn't work, try parsing as ISO 8601 string (with time)
                        if (!parsedDate || !parsedDate.isValid()) {
                            parsedDate = moment(row.value);
                        }
                        
                        if (parsedDate.isValid()) {
                            // Use moment.js locale-aware formatting (no warning on date itself)
                            // Ensure locale is set
                            var locale = 'en';
                            if (typeof i18n !== 'undefined' && i18n.lng) {
                                locale = i18n.lng();
                                moment.locale(locale);
                            }
                            
                            // Use 'll' format which is locale-aware short date
                            var formatted = '<span title="' + parsedDate.format('llll') + '">' + parsedDate.format('ll') + '</span>';
                            td.html(formatted);
                        } else {
                            td.text(row.value);
                        }
                    } else {
                        td.text('');
                    }
                }
                // Format isRenewable: Yes = blue, No = yellow
                else if (row.type === 'isRenewable') {
                    var isYes = (row.value == '1' || row.value == 'true' || row.value === true || String(row.value).toLowerCase() === 'true');
                    var displayText = isYes ? i18n.t('Yes') : i18n.t('No');
                    if (isYes) {
                        td.html('<span class="label label-info">' + displayText + '</span>');
                    } else {
                        td.html('<span class="label label-warning">' + displayText + '</span>');
                    }
                }
                // Format isCanceled: Yes = red, No = green
                else if (row.type === 'isCanceled') {
                    var isYes = (row.value == '1' || row.value == 'true' || row.value === true || String(row.value).toLowerCase() === 'true');
                    var displayText = isYes ? i18n.t('Yes') : i18n.t('No');
                    if (isYes) {
                        td.html('<span class="label label-danger">' + displayText + '</span>');
                    } else {
                        td.html('<span class="label label-success">' + displayText + '</span>');
                    }
                }
                else {
                    td.text(row.value);
                }
                
                tbody.append(
                    $('<tr>')
                        .append($('<th>').text(row.label))
                        .append(td)
                );
            }
        });

        table.append(tbody);
    });

});

</script>
