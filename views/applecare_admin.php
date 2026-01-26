<?php $this->view('partials/head')?>

<div class="container">
    <div class="row">
        <div class="col-lg-6">
            <h3>AppleCare Admin</h3>
            <p>Run the AppleCare sync script and inspect the output.</p>
            <div class="alert alert-warning">
                <strong>Warning:</strong> The sync will stop if you close this page.
                <br><strong>Connection info:</strong> If the connection times out, the sync will automatically resume from where it left off. For very long or automated syncs, the CLI script is available: <code>php sync_applecare.php</code>
                <div style="padding-top: 4px;"><strong>Devices to process:</strong> <span id="device-count-display">Loading...</span></div>
            </div>

            <div class="form-group" style="margin-top: 15px;">
                <button id="sync-applecare" class="btn btn-primary">
                    <i class="fa fa-refresh"></i> Run AppleCare Sync
                </button>
                <button id="stop-sync" class="btn btn-danger" style="margin-left:8px; display:none;" data-toggle="tooltip" data-placement="top" title="Stop the running sync. Progress will be saved and you can resume later.">
                    <i class="fa fa-stop"></i> Stop Sync
                </button>
                <button id="reset-progress" class="btn btn-warning" style="margin-left:8px;" data-toggle="tooltip" data-placement="top" title="Clear saved sync progress. The next sync will start from the beginning instead of resuming from where it left off.">
                    <i class="fa fa-undo"></i> Reset Progress
                </button>
                <label class="checkbox-inline" style="margin-left:15px;">
                    <input type="checkbox" id="exclude-existing-checkbox"> Exclude devices with existing AppleCare records
                </label>
                <span id="sync-status" class="text-muted" style="margin-left:8px;"></span>
            </div>
            
            <div id="sync-completion-message" style="display:none;margin-bottom:15px;"></div>

            <div id="sync-progress" class="progress hide" style="margin-bottom:15px;">
                <div class="progress-bar progress-bar-success progress-bar-striped active" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" style="min-width: 2em; width: 0%;">
                    <span id="progress-bar-percent">0%</span>
                </div>
            </div>

            <div class="panel panel-default">
                <div class="panel-heading">
                    <strong>Sync Output</strong>
                    <span id="estimated-time-display" class="pull-right" style="font-weight: bold; font-size: 1em; color: #5bc0de !important; background-color: rgba(91, 192, 222, 0.2) !important; padding: 2px 8px !important; border-radius: 3px !important;"></span>
                </div>
                <div class="panel-body">
                    <pre id="sync-output" style="white-space:pre-wrap;min-height:120px;max-height:300px;overflow-y:auto;background-color:#f5f5f5;padding:10px;border:1px solid #ddd;border-radius:4px;font-family:monospace;font-size:12px;">Waiting to run…</pre>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <h3 style="margin-top: 0;">&nbsp;</h3>
            <p style="margin-bottom: 15px;">&nbsp;</p>
            <h3><i class="fa fa-info-circle"></i> <span data-i18n="applecare.system_status.title">System Status</span></h3>
            <div id="AppleCare-System-Status"></div>
        </div>
    </div>
</div>

<script>
(function(){
    var $btn = $('#sync-applecare');
    var $excludeCheckbox = $('#exclude-existing-checkbox');
    var $status = $('#sync-status');
    var $deviceCountDisplay = $('#device-count-display');
    var $output = $('#sync-output');
    var $completionMsg = $('#sync-completion-message');
    // Polling-based sync (IIS compatible - no SSE)
    var outputBuffer = '';
    var errorCount = 0;
    var skippedCount = 0;
    var syncedCount = 0;
    var totalDevices = 0;
    var processedDevices = 0;
    var devicesPerMinute = 8; // Default, will be updated from server config
    
    // Load admin status data (similar to jamf_admin.php)
    $.getJSON(appUrl + '/module/applecare/get_admin_data', function(data) {
        // Calculate devices per minute from rate limit (80% of limit, 3 requests per device)
        if (data.rate_limit) {
            var effectiveRateLimit = Math.floor(data.rate_limit * 0.8);
            var requestsPerDevice = 3;
            devicesPerMinute = effectiveRateLimit / requestsPerDevice;
        }
        
        // Display connection timeout info
        // Note: PHP execution time limit is disabled for sync operations
        // The actual timeout is from web server/proxy/SSE connection limits
        // (Text is now static in HTML, no need to set via JavaScript)
        
        var statusRows = '<table class="table table-striped"><tbody>';
        
        // API URL configured
        statusRows += '<tr><th>API URL Configured</th><td>' + 
            (data.api_url_configured ? '<span class="label label-success">' + i18n.t('yes') + '</span>' : '<span class="label label-danger">' + i18n.t('no') + '</span>') + 
            '</td></tr>';
        
        // Client Assertion configured
        statusRows += '<tr><th>Client Assertion Configured</th><td>' + 
            (data.client_assertion_configured ? '<span class="label label-success">' + i18n.t('yes') + '</span>' : '<span class="label label-danger">' + i18n.t('no') + '</span>') + 
            '</td></tr>';
        
        // Rate Limit
        statusRows += '<tr><th>Rate Limit</th><td>' + data.rate_limit + ' requests/minute (' + devicesPerMinute.toFixed(1) + ' devices/min)</td></tr>';
        
        // Show API URL if configured (masked for security)
        if (data.default_api_url) {
            var maskedUrl = data.default_api_url.replace(/https?:\/\/([^\/]+)/, function(match, domain) {
                return match.replace(domain, '***');
            });
            statusRows += '<tr><th>API URL</th><td><code>' + maskedUrl + '</code></td></tr>';
        }
        
        // Reseller Config File Status
        if (data.reseller_config) {
            var resellerStatus = '';
            var resellerLabel = 'label-default';
            
            if (data.reseller_config.valid) {
                resellerStatus = '<span class="label label-success">Valid</span> (' + data.reseller_config.entry_count + ' entries)';
                resellerLabel = 'label-success';
            } else if (data.reseller_config.exists && data.reseller_config.readable) {
                resellerStatus = '<span class="label label-warning">Invalid</span>';
                if (data.reseller_config.error) {
                    resellerStatus += '<br><small style="color: #dc3545;">' + data.reseller_config.error + '</small>';
                }
            } else if (data.reseller_config.exists) {
                resellerStatus = '<span class="label label-danger">Not Readable</span>';
                if (data.reseller_config.error) {
                    resellerStatus += '<br><small style="color: #dc3545;">' + data.reseller_config.error + '</small>';
                }
            } else {
                resellerStatus = '<span class="label label-default">Not Found</span>';
                if (data.reseller_config.error) {
                    resellerStatus += '<br><small style="color: #6c757d;">' + data.reseller_config.error + '</small>';
                }
            }
            
            statusRows += '<tr><th>Reseller Config</th><td>' + resellerStatus;
            if (data.reseller_config.path) {
                statusRows += '<br><small style="color: #6c757d;"><code>' + data.reseller_config.path + '</code></small>';
            }
            statusRows += '</td></tr>';
        }
        
        statusRows += '</tbody></table>';
        $('#AppleCare-System-Status').html(statusRows);
        
        // Now that we have the rate limit, update the device count display
        updateDeviceCount();
    }).fail(function() {
        $('#AppleCare-System-Status').html('<div class="alert alert-warning">Unable to load system status</div>');
        // Still load device count even if admin data fails (will use default rate)
        updateDeviceCount();
    });

    // Load device count and update display
    function updateDeviceCount() {
        var excludeExisting = $excludeCheckbox.is(':checked');
        var url = appUrl + '/module/applecare/get_device_count';
        
        $.ajax({
            url: url,
            method: 'POST',
            data: { exclude_existing: excludeExisting ? '1' : '0' },
            dataType: 'json',
            success: function(data) {
                if (data.count !== undefined) {
                    var count = data.count;
                    var text = count + ' device' + (count !== 1 ? 's' : '');
                    if (excludeExisting) {
                        text += ' (excluding devices with existing records)';
                    }
                    $deviceCountDisplay.text(text);
                    
                    // Calculate and display estimated time using configured rate limit
                    if (count > 0) {
                        var estimatedSeconds = Math.ceil((count / devicesPerMinute) * 60);
                        updateEstimatedTime(estimatedSeconds, count);
                    } else {
                        updateEstimatedTime(0, 0);
                    }
                } else {
                    $deviceCountDisplay.text('Unable to load count');
                    updateEstimatedTime(0, 0);
                }
            },
            error: function() {
                $deviceCountDisplay.text('Unable to load count');
                updateEstimatedTime(0, 0);
            }
        });
    }

    // Update count when checkbox changes
    $excludeCheckbox.on('change', function() {
        updateDeviceCount();
    });
    
    // Note: Initial updateDeviceCount() is called after get_admin_data succeeds
    // to ensure we have the correct rate limit before calculating estimated time

    function updateProgress() {
        if (totalDevices > 0) {
            var percent = Math.round((processedDevices / totalDevices) * 100);
            $('#sync-progress .progress-bar').css('width', percent + '%').attr('aria-valuenow', percent);
            $('#progress-bar-percent').text(processedDevices + '/' + totalDevices + ' (' + percent + '%)');
            
            // Update estimated time using configured rate limit
            var remainingDevices = totalDevices - processedDevices;
            if (remainingDevices > 0) {
                var estimatedSeconds = Math.ceil((remainingDevices / devicesPerMinute) * 60);
                updateEstimatedTime(estimatedSeconds, remainingDevices);
            } else {
                updateEstimatedTime(0, 0);
            }
        }
    }
    
    function updateEstimatedTime(estimatedSeconds, remainingDevices) {
        if (estimatedSeconds > 0 && remainingDevices > 0) {
            var minutes = Math.floor(estimatedSeconds / 60);
            var seconds = estimatedSeconds % 60;
            var timeText = '';
            if (minutes > 0) {
                timeText = minutes + 'm ' + seconds + 's';
            } else {
                timeText = seconds + 's';
            }
            $('#estimated-time-display').text('Est. remaining: ' + timeText + ' (' + remainingDevices + ' device' + (remainingDevices !== 1 ? 's' : '') + ')');
        } else {
            $('#estimated-time-display').text('');
        }
    }

    function appendOutput(text){
        if (text) {
            outputBuffer += text + '\n';
            
            // Color code the output
            var coloredBuffer = outputBuffer;
            
            // Color patterns
            // Success/OK messages - green
            coloredBuffer = coloredBuffer.replace(/(OK \(.*?\))/g, '<span style="color: #28a745; font-weight: bold;">$1</span>');
            coloredBuffer = coloredBuffer.replace(/(✓[^\n]*)/g, '<span style="color: #28a745;">$1</span>');
            
            // Error messages - red
            coloredBuffer = coloredBuffer.replace(/(ERROR[^\n]*)/gi, '<span style="color: #dc3545; font-weight: bold;">$1</span>');
            coloredBuffer = coloredBuffer.replace(/(error[^\n]*)/gi, '<span style="color: #dc3545;">$1</span>');
            
            // Skip messages - darker orange for better readability
            coloredBuffer = coloredBuffer.replace(/(SKIP[^\n]*)/gi, '<span style="color: #e67e22;">$1</span>');
            
            // Warning messages - orange
            coloredBuffer = coloredBuffer.replace(/(WARNING[^\n]*)/gi, '<span style="color: #fd7e14;">$1</span>');
            
            // Info/status messages - blue
            coloredBuffer = coloredBuffer.replace(/(Processing [^\n]*)/g, '<span style="color: #007bff;">$1</span>');
            coloredBuffer = coloredBuffer.replace(/(Rate limit[^\n]*)/gi, '<span style="color: #6c757d;">$1</span>');
            coloredBuffer = coloredBuffer.replace(/(Sleeping[^\n]*)/gi, '<span style="color: #6c757d;">$1</span>');
            
            // Headers/section dividers - bold
            coloredBuffer = coloredBuffer.replace(/(={50,})/g, '<span style="color: #6c757d;">$1</span>');
            coloredBuffer = coloredBuffer.replace(/(Sync Complete|AppleCare Sync Tool|Total devices|Synced|Skipped|Errors|Total time|Exit code)/g, '<span style="font-weight: bold;">$1</span>');
            
            $output.html(coloredBuffer);
            // Auto-scroll to bottom
            $output.scrollTop($output[0].scrollHeight);
        }
    }

    function clearOutput(){
        outputBuffer = '';
        $output.text('');
        $completionMsg.hide().empty();
        errorCount = 0;
        skippedCount = 0;
        syncedCount = 0;
        totalDevices = 0;
        processedDevices = 0;
        $('#sync-progress').addClass('hide');
        $('#sync-progress .progress-bar').css('width', '0%').attr('aria-valuenow', 0);
        $('#progress-bar-percent').text('0%');
        $('#estimated-time-display').text('');
    }
    
    function showCompletionMessage(success, data) {
        var alertClass = 'alert-success';
        var icon = 'fa-check-circle';
        var title = 'Sync Completed Successfully';
        var message = '';
        
        // Parse summary from output buffer to get counts
        var summaryMatch = outputBuffer.match(/Total devices:\s*(\d+)[\s\S]*?Synced:\s*(\d+)[\s\S]*?Skipped:\s*(\d+)[\s\S]*?Errors:\s*(\d+)/);
        if (summaryMatch) {
            var total = parseInt(summaryMatch[1]) || 0;
            syncedCount = parseInt(summaryMatch[2]) || 0;
            skippedCount = parseInt(summaryMatch[3]) || 0;
            errorCount = parseInt(summaryMatch[4]) || 0;
        }
        
        // Determine message type based on results
        if (errorCount > 0) {
            alertClass = 'alert-danger';
            icon = 'fa-exclamation-triangle';
            title = 'Sync Completed with Errors';
            message = 'Errors: ' + errorCount + ', Synced: ' + syncedCount + ', Skipped: ' + skippedCount;
        } else if (skippedCount > 0 && syncedCount === 0) {
            alertClass = 'alert-warning';
            icon = 'fa-exclamation-circle';
            title = 'Sync Completed with Warnings';
            message = 'All devices were skipped. This may indicate configuration issues or devices not found in Apple Business Manager.';
        } else if (skippedCount > 0) {
            alertClass = 'alert-warning';
            icon = 'fa-exclamation-circle';
            title = 'Sync Completed with Warnings';
            message = 'Synced: ' + syncedCount + ', Skipped: ' + skippedCount + ' (some devices may not be in Apple Business Manager)';
        } else if (syncedCount > 0) {
            alertClass = 'alert-success';
            icon = 'fa-check-circle';
            title = 'Sync Completed Successfully';
            message = 'Successfully synced ' + syncedCount + ' device(s)';
        } else if (!success) {
            alertClass = 'alert-danger';
            icon = 'fa-times-circle';
            title = 'Sync Failed';
            message = data.message || 'Sync encountered an error';
        } else {
            alertClass = 'alert-info';
            icon = 'fa-info-circle';
            title = 'Sync Completed';
            message = 'No devices were processed';
        }
        
        $completionMsg
            .removeClass('alert-success alert-warning alert-danger alert-info')
            .addClass('alert ' + alertClass)
            .html('<i class="fa ' + icon + '"></i> <strong>' + title + '</strong>' + (message ? '<br>' + message : ''))
            .show();
    }

    function stopSync(){
        stopPolling();
        syncRunning = false;
        $btn.prop('disabled', false);
        $excludeCheckbox.prop('disabled', false);
        $('#stop-sync').hide();
        $('#reset-progress').prop('disabled', false);
    }

    var pollTimer = null;
    var syncRunning = false;
    
    function startSync() {
        // Prevent multiple simultaneous syncs
        if (syncRunning) {
            return;
        }

        var excludeExisting = $excludeCheckbox.is(':checked');
        
        $btn.prop('disabled', true);
        $excludeCheckbox.prop('disabled', true);
        $('#stop-sync').show();
        $('#reset-progress').prop('disabled', true);
        $status.text('Starting…');
        
        clearOutput();
        
        if (excludeExisting) {
            appendOutput('Starting AppleCare sync (excluding devices with existing records)...\n');
        } else {
            appendOutput('Starting AppleCare sync...\n');
        }
        
        // Show progress bar immediately (will be updated with actual counts)
        $('#sync-progress').removeClass('hide');

        // Start sync via AJAX (polling-based approach for IIS compatibility)
        var url = appUrl + '/module/applecare/startsync';
        
        console.log('Starting sync, URL:', url);
        console.log('Exclude existing checked:', excludeExisting);
        appendOutput('Exclude existing: ' + (excludeExisting ? 'YES' : 'NO') + '\n');
        
        $.ajax({
            url: url,
            method: 'POST',
            data: { exclude_existing: excludeExisting ? '1' : '0' },
            dataType: 'json',
            success: function(data) {
                console.log('startsync response:', data);
                
                if (data.success) {
                    syncRunning = true;
                    $status.text('Running…');
                    
                    // Display initial output
                    if (data.output) {
                        appendOutput(data.output + '\n');
                    }
                    
                    // Set total devices if provided
                    if (data.total) {
                        totalDevices = data.total;
                        $('#sync-progress .progress-bar').attr('aria-valuemax', totalDevices);
                    }
                    
                    // Check if already complete (no devices to process)
                    if (data.complete) {
                        $status.text('Finished');
                        showCompletionMessage(true, {});
                        stopSync();
                        return;
                    }
                    
                    // Start processing chunks
                    startPolling();
                } else {
                    appendOutput('ERROR: ' + (data.message || 'Failed to start sync') + '\n');
                    if (data.output) {
                        appendOutput(data.output + '\n');
                    }
                    $status.text('Failed');
                    stopSync();
                }
            },
            error: function(xhr, status, error) {
                console.log('startsync error:', status, error, xhr.responseText);
                appendOutput('ERROR: Failed to start sync - ' + error + '\n');
                $status.text('Failed');
                stopSync();
            }
        });
    }
    
    function startPolling() {
        // Poll every 2 seconds
        pollTimer = setInterval(function() {
            pollSyncOutput();
        }, 2000);
        
        // Also poll immediately
        pollSyncOutput();
    }
    
    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }
    
    function pollSyncOutput() {
        console.log('Processing next chunk...');
        $.ajax({
            url: appUrl + '/module/applecare/syncchunk',
            method: 'GET',
            dataType: 'json',
            success: function(data) {
                console.log('Chunk response:', data);
                
                // Display output from this chunk
                if (data.output && data.output.length > 0) {
                    appendOutput(data.output);
                }
                
                // Update progress
                if (data.progress) {
                    if (data.progress.total > 0) {
                        totalDevices = data.progress.total;
                        $('#sync-progress .progress-bar').attr('aria-valuemax', totalDevices);
                    }
                    
                    processedDevices = data.progress.processed || 0;
                    syncedCount = data.progress.synced || 0;
                    skippedCount = data.progress.skipped || 0;
                    errorCount = data.progress.errors || 0;
                    
                    updateProgress();
                    
                    // Update estimated time
                    if (totalDevices > 0) {
                        var remainingDevices = totalDevices - processedDevices;
                        if (remainingDevices > 0) {
                            var estimatedSeconds = Math.ceil((remainingDevices / devicesPerMinute) * 60);
                            updateEstimatedTime(estimatedSeconds, remainingDevices);
                        } else {
                            updateEstimatedTime(0, 0);
                        }
                    }
                }
                
                if (data.complete || !data.running) {
                    // Sync finished
                    console.log('Sync complete');
                    stopPolling();
                    syncRunning = false;
                    
                    var success = errorCount === 0;
                    $status.text(success ? 'Finished' : 'Finished with errors');
                    showCompletionMessage(success, {});
                    
                    // Hide progress bar with fade
                    if (totalDevices > 0) {
                        $("#sync-progress").fadeOut(1200, function() {
                            $('#sync-progress').addClass('hide');
                            var progresselement = document.getElementById('sync-progress');
                            if (progresselement) {
                                progresselement.style.display = null;
                                progresselement.style.opacity = null;
                            }
                            $('#sync-progress .progress-bar').css('width', '0%').attr('aria-valuenow', 0);
                        });
                    }
                    
                    // Clear sync status on server
                    $.ajax({
                        url: appUrl + '/module/applecare/clear_sync_status',
                        method: 'POST'
                    });
                    
                    stopSync();
                } else if (data.waiting) {
                    // Rate limiting - wait before next request
                    console.log('Rate limiting, waiting ' + data.wait_time + 's');
                }
            },
            error: function(xhr, status, error) {
                console.log('Chunk error:', error, xhr.responseText);
                appendOutput('ERROR: Chunk processing failed - ' + error + '\n');
            }
        });
    }
    
    function processOutput(output) {
        // Extract total devices count from "Found X devices" message
        var foundMatch = output.match(/Found\s+(\d+)\s+devices/i);
        if (foundMatch && totalDevices === 0) {
            totalDevices = parseInt(foundMatch[1]) || 0;
            if (totalDevices > 0) {
                $('#sync-progress').removeClass('hide');
                $('#sync-progress .progress-bar').attr('aria-valuemax', totalDevices);
            }
        }
        
        // Track completed devices from result messages
        var okMatches = output.match(/\bOK\s*\(/g);
        var skipMatches = output.match(/\bSKIP\s*\(/g);
        var errorMatches = output.match(/\bERROR\s*\(/g);
        
        if (okMatches) syncedCount += okMatches.length;
        if (skipMatches) skippedCount += skipMatches.length;
        if (errorMatches) errorCount += errorMatches.length;
        
        processedDevices = syncedCount + skippedCount + errorCount;
        
        // Update estimated time
        if (totalDevices > 0) {
            var remainingDevices = totalDevices - processedDevices;
            if (remainingDevices > 0) {
                var estimatedSeconds = Math.ceil((remainingDevices / devicesPerMinute) * 60);
                updateEstimatedTime(estimatedSeconds, remainingDevices);
            } else {
                updateEstimatedTime(0, 0);
            }
        }
    }

    $btn.on('click', function(){
        startSync();
    });
    
    // Stop sync button
    $('#stop-sync').on('click', function(){
        var $stopBtn = $(this);
        var originalText = $stopBtn.html();
        
        // Disable button and show loading state
        $stopBtn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Stopping...');
        
        $.ajax({
            url: appUrl + '/module/applecare/stop_sync',
            method: 'POST',
            dataType: 'json',
            success: function(data) {
                if (data.success) {
                    // Show success message
                    $stopBtn.html('<i class="fa fa-check"></i> Stop Signal Sent').removeClass('btn-danger').addClass('btn-success');
                    appendOutput('Stop signal sent. Sync will stop after processing current device...\n');
                    
                    // Stop polling
                    stopPolling();
                    
                    // Update UI
                    setTimeout(function() {
                        stopSync();
                        $stopBtn.html(originalText).removeClass('btn-success').addClass('btn-danger').prop('disabled', false);
                    }, 2000);
                } else {
                    alert('Failed to stop sync: ' + (data.message || 'Unknown error'));
                    $stopBtn.html(originalText).prop('disabled', false);
                }
            },
            error: function(xhr, status, error) {
                var errorMsg = 'Failed to stop sync';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMsg += ': ' + xhr.responseJSON.message;
                } else {
                    errorMsg += ': ' + error;
                }
                alert(errorMsg);
                $stopBtn.html(originalText).prop('disabled', false);
            }
        });
    });
    
    // Reset progress button
    $('#reset-progress').on('click', function(){
        var $resetBtn = $(this);
        var originalText = $resetBtn.html();
        
        // Disable button and show loading state
        $resetBtn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Resetting...');
        
        $.ajax({
            url: appUrl + '/module/applecare/reset_progress',
            method: 'POST',
            dataType: 'json',
            success: function(data) {
                if (data.success) {
                    // Reset all progress-related UI elements
                    totalDevices = 0;
                    processedDevices = 0;
                    $('#sync-progress').addClass('hide');
                    $('#sync-progress .progress-bar').css('width', '0%').attr('aria-valuenow', 0);
                    $('#progress-bar-percent').text('0%');
                    $('#estimated-time-display').text('');
                    
                    // Recalculate estimated time based on current device count
                    updateDeviceCount();
                    
                    // Show success message
                    $resetBtn.html('<i class="fa fa-check"></i> Reset').removeClass('btn-warning').addClass('btn-success');
                    setTimeout(function() {
                        $resetBtn.html(originalText).removeClass('btn-success').addClass('btn-warning').prop('disabled', false);
                        // Update tooltip to reflect cleared progress
                        updateResetProgressTooltip();
                    }, 2000);
                    
                    // Show notification
                    appendOutput('Sync progress has been reset.\n');
                } else {
                    alert('Failed to reset progress: ' + (data.message || 'Unknown error'));
                    $resetBtn.html(originalText).prop('disabled', false);
                }
            },
            error: function(xhr, status, error) {
                var errorMsg = 'Failed to reset progress';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMsg += ': ' + xhr.responseJSON.message;
                } else {
                    errorMsg += ': ' + error;
                }
                alert(errorMsg);
                $resetBtn.html(originalText).prop('disabled', false);
            }
        });
    });
    
    // Function to update reset progress tooltip with remaining device count
    function updateResetProgressTooltip() {
        $.getJSON(appUrl + '/module/applecare/get_progress', function(data) {
            var $resetBtn = $('#reset-progress');
            var tooltipText;
            
            if (data.success && data.has_progress && data.remaining > 0) {
                tooltipText = 'Clear saved sync progress (' + data.remaining + ' device' + (data.remaining !== 1 ? 's' : '') + ' remaining). The next sync will start from the beginning instead of resuming.';
            } else {
                tooltipText = 'Clear saved sync progress. The next sync will start from the beginning instead of resuming from where it left off.';
            }
            
            // Update tooltip - destroy and recreate to ensure it updates
            $resetBtn.attr('title', tooltipText);
            if ($resetBtn.data('bs.tooltip')) {
                $resetBtn.tooltip('destroy');
            }
            $resetBtn.tooltip();
        }).fail(function() {
            // On error, use default tooltip
            var $resetBtn = $('#reset-progress');
            var tooltipText = 'Clear saved sync progress. The next sync will start from the beginning instead of resuming from where it left off.';
            $resetBtn.attr('title', tooltipText);
            if ($resetBtn.data('bs.tooltip')) {
                $resetBtn.tooltip('destroy');
            }
            $resetBtn.tooltip();
        });
    }
    
    // Initialize tooltips first
    $('[data-toggle="tooltip"]').tooltip();
    
    // Load progress count on page load and update tooltip
    updateResetProgressTooltip();
})();
</script>

<?php $this->view('partials/foot'); ?>