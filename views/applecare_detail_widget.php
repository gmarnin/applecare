<div class="col-lg-4">
	<h4><i class="fa fa-medkit"></i> <span data-i18n="applecare.title"></span></h4>
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
            { label: i18n.t('applecare.status'), value: data.status },
            { label: i18n.t('applecare.description'), value: data.description },
            { label: i18n.t('applecare.column.endDateTime'), value: data.endDateTime }
        ];

        var tbody = $('<tbody>');
        rows.forEach(function (row) {
            if (row.value !== null && row.value !== undefined && row.value !== '') {
                tbody.append(
                    $('<tr>')
                        .append($('<th>').text(row.label))
                        .append($('<td>').text(row.value))
                );
            }
        });

        table.append(tbody);
    });

});

</script>
