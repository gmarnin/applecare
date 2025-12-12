<?php
/**
 * AppleCare Widget
 * 
 * Dashboard widget showing AppleCare coverage statistics
 * Loads data via AJAX from the controller
 */
?>

<div class="col-lg-4 col-md-6">
    <div class="panel panel-default" id="applecare-widget">
        <div class="panel-heading" data-container="body">
            <h3 class="panel-title">
                <i class="fa fa-shield"></i>
                <span data-i18n="applecare.widget_title">AppleCare Coverage</span>
                <a href="<?= url('show/listing/applecare/applecare') ?>" class="pull-right">
                    <i class="fa fa-list"></i>
                </a>
            </h3>
        </div>
        <div class="panel-body">
            <table class="table table-condensed">
                <tbody>
                    <tr>
                        <td>
                            <span data-i18n="applecare.total_devices">Total Devices</span>
                        </td>
                        <td class="text-right">
                            <a href="<?= url('show/listing/applecare/applecare') ?>">
                                <span class="badge" id="applecare-total-devices">0</span>
                            </a>
                        </td>
                    </tr>
                    <tr class="success">
                        <td>
                            <span data-i18n="applecare.active">Active Coverage</span>
                        </td>
                        <td class="text-right">
                            <a href="<?= url('show/listing/applecare/applecare?status=ACTIVE') ?>">
                                <span class="badge" id="applecare-active">0</span>
                            </a>
                        </td>
                    </tr>
                    <tr class="warning">
                        <td>
                            <span data-i18n="applecare.expiring_soon">Expiring Soon (30 days)</span>
                        </td>
                        <td class="text-right">
                            <a href="<?= url('show/listing/applecare/applecare?expiring=1') ?>">
                                <span class="badge" id="applecare-expiring">0</span>
                            </a>
                        </td>
                    </tr>
                    <tr class="danger">
                        <td>
                            <span data-i18n="applecare.expired">Expired</span>
                        </td>
                        <td class="text-right">
                            <a href="<?= url('show/listing/applecare/applecare?expired=1') ?>">
                                <span class="badge" id="applecare-expired">0</span>
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <span data-i18n="applecare.inactive">Inactive</span>
                        </td>
                        <td class="text-right">
                            <a href="<?= url('show/listing/applecare/applecare?status=INACTIVE') ?>">
                                <span class="badge" id="applecare-inactive">0</span>
                            </a>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
$(document).on('appReady', function() {
    $.getJSON(appUrl + '/module/applecare/get_stats', function(data) {
        $('#applecare-total-devices').text(data.total_devices || 0);
        $('#applecare-active').text(data.active || 0);
        $('#applecare-expiring').text(data.expiring_soon || 0);
        $('#applecare-expired').text(data.expired || 0);
        $('#applecare-inactive').text(data.inactive || 0);
    });
});
</script>
