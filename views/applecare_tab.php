<div id="applecare-tab"></div>
<h2 data-i18n="applecare.title"></h2>

<div style="margin-bottom: 15px;">
    <button id="applecare-sync-btn" class="btn btn-default btn-sm">
        <i class="fa fa-refresh"></i> <span data-i18n="applecare.sync_now">Sync Now</span>
    </button>
</div>

<table id="applecare-tab-table" style="max-width:475px;"><tbody></tbody></table>

<div id="applecare-sync-message" style="margin-top: 10px;"></div>

<script>
$(document).on('appReady', function(){
    var loadAppleCareData = function() {
        $.getJSON(appUrl + '/module/applecare/get_data/' + serialNumber, function(data){
            var table = $('#applecare-tab-table');
            table.find('tbody').empty();
            
            if (!data || $.isEmptyObject(data)) {
                table.find('tbody').append(
                    $('<tr>').append(
                        $('<td>')
                            .attr('colspan', 2)
                            .addClass('text-muted')
                            .text(i18n.t('no_data'))
                    )
                );
                return;
            }
            
            $.each(data, function(key,val){
                if (val !== null && val !== undefined && val !== '') {
                    var th = $('<th>').text(i18n.t('applecare.column.' + key));
                    var td = $('<td>').text(val);
                    table.find('tbody').append($('<tr>').append(th, td));
                }
            });
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
