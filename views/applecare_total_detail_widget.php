<div class="col-lg-4 col-md-6">
    <div class="panel panel-default" id="applecare-widget">
        <div class="panel-heading" data-container="body">
            <h3 class="panel-title"><i class="fa fa-shield"></i>
                <span data-i18n="applecare.widget_title"></span>
				<!-- <span class="applecare_counter badge"></span> -->
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

        // Inactive/Expired: is_primary=1 and coverage_status=inactive
        var inactiveLink = $('<a>')
            .attr('href', baseUrl + 'is_primary=1&coverage_status=inactive')
            .addClass('btn btn-danger')
            .attr('title', i18n.t('applecare.inactive_tooltip'))
            .attr('data-toggle', 'tooltip')
            .attr('data-placement', 'top')
            .append($('<span>').addClass('bigger-150').text(data.inactive))
            .append('<br>')
            .append(document.createTextNode(i18n.t('applecare.inactive')));
        panel.append(inactiveLink);

        // Expiring Soon: is_primary=1 and coverage_status=expiring_soon
        var expiringLink = $('<a>')
            .attr('href', baseUrl + 'is_primary=1&coverage_status=expiring_soon')
            .addClass('btn btn-warning')
            .attr('title', i18n.t('applecare.expiring_soon_tooltip'))
            .attr('data-toggle', 'tooltip')
            .attr('data-placement', 'top')
            .append($('<span>').addClass('bigger-150').text(data.expiring_soon))
            .append('<br>')
            .append(document.createTextNode(i18n.t('applecare.expiring_soon')));
        panel.append(expiringLink);

        // Active: is_primary=1 and coverage_status=active
        var activeLink = $('<a>')
            .attr('href', baseUrl + 'is_primary=1&coverage_status=active')
            .addClass('btn btn-success')
            .attr('title', i18n.t('applecare.active_tooltip'))
            .attr('data-toggle', 'tooltip')
            .attr('data-placement', 'top')
            .append($('<span>').addClass('bigger-150').text(data.active))
            .append('<br>')
            .append(document.createTextNode(i18n.t('applecare.active')));
        panel.append(activeLink);

        // Initialize tooltips
        panel.find('[data-toggle="tooltip"]').tooltip();
    });
});

</script>