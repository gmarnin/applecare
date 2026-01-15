<div class="col-lg-4 col-md-6">
    <div class="panel panel-default" id="applecare-widget">
        <div class="panel-heading" data-container="body">
            <h3 class="panel-title"><i class="fa fa-shield"></i>
                <span data-i18n="applecare.widget_title"></span>
				<span class="applecare_counter badge"></span>
                <list-link data-url="/show/listing/applecare/applecare"></list-link>
            </h3>
        </div>
        <div class="panel-body text-center"></div>
    </div><!-- /panel -->
</div><!-- /col -->


<script>
$(document).on('appReady', function(){
    // Add tooltip to panel heading
    $('#applecare-widget>div.panel-heading')
        .attr('title', i18n.t('applecare.widget_tooltip'))
        .tooltip();
});

$(document).on('appUpdate', function(e, lang) {

    $.getJSON( appUrl + '/module/applecare/get_stats', function( data ) {

        if(data.error){
            //alert(data.error);
            return;
        }

        var panel = $('#applecare-widget div.panel-body'),
            baseUrl = appUrl + '/show/listing/applecare/applecare#';
        panel.empty();

        $('#applecare-widget .applecare_counter').html(data.total_devices);

        // Set statuses - use regular links like other modules (green, yellow, red order)
        panel.append(' <a href="'+baseUrl+'status=ACTIVE" class="btn btn-success"><span class="bigger-150">'+data.active+'</span><br>'+i18n.t('applecare.active')+'</a>');
        var expiringLink = $('<a>')
            .attr('href', baseUrl+'expiring=1')
            .addClass('btn btn-warning')
            .attr('title', i18n.t('applecare.expiring_soon_tooltip'))
            .attr('data-toggle', 'tooltip')
            .attr('data-placement', 'top')
            .append($('<span>').addClass('bigger-150').text(data.expiring_soon))
            .append('<br>')
            .append(document.createTextNode(i18n.t('applecare.expiring_soon')));
        panel.append(expiringLink);
        panel.append(' <a href="'+baseUrl+'status=INACTIVE" class="btn btn-danger"><span class="bigger-150">'+data.inactive+'</span><br>'+i18n.t('applecare.inactive')+'</a>');
        
        // Initialize tooltips
        panel.find('[data-toggle="tooltip"]').tooltip();
    });
});

</script>