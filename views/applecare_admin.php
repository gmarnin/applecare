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
    var eventSource = null;
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
        if (excludeExisting) {
            url += '?exclude_existing=1';
        }
        
        $.getJSON(url, function(data) {
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
        }).fail(function() {
            $deviceCountDisplay.text('Unable to load count');
            updateEstimatedTime(0, 0);
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
        if (eventSource) {
            eventSource.close();
            eventSource = null;
        }
        $btn.prop('disabled', false);
        $excludeCheckbox.prop('disabled', false);
        $('#stop-sync').hide();
        $('#reset-progress').prop('disabled', false);
        
        // Reset auto-resume state when manually stopped
        if (typeof resetAutoResumeState === 'function') {
            resetAutoResumeState();
        }
    }

    function startSync() {
        // Prevent multiple simultaneous syncs
        if (eventSource && eventSource.readyState !== EventSource.CLOSED) {
            return;
        }

        var excludeExisting = $excludeCheckbox.is(':checked');
        
        // Reset auto-resume state on manual start (not auto-resume)
        if ($status.text() !== 'Auto-resuming...') {
            resetAutoResumeState();
        }
        
        $btn.prop('disabled', true);
        $excludeCheckbox.prop('disabled', true);
        $('#stop-sync').show();
        $('#reset-progress').prop('disabled', true);
        $status.text('Running…');
        
        // Don't clear output on auto-resume to preserve history
        if (autoResumeAttempts === 0) {
            clearOutput();
        }
        
        if (excludeExisting) {
            appendOutput('Starting AppleCare sync (excluding devices with existing records)...\n');
        } else {
            appendOutput('Starting AppleCare sync...\n');
        }
        
        // Show progress bar immediately (will be updated with actual counts)
        $('#sync-progress').removeClass('hide');

        // Use Server-Sent Events for real-time streaming
        var url = appUrl + '/module/applecare/sync?stream=1';
        if (excludeExisting) {
            url += '&exclude_existing=1';
        }
        eventSource = new EventSource(url);

        eventSource.onopen = function() {
            appendOutput('Connected to sync process...\n');
        };

        eventSource.addEventListener('resume', function(e) {
            var resumeData = JSON.parse(e.data);
            totalDevices = resumeData.total || 0;
            processedDevices = resumeData.processed || 0;
            
            if (totalDevices > 0) {
                $('#sync-progress').removeClass('hide');
                $('#sync-progress .progress-bar').attr('aria-valuemax', totalDevices);
                updateProgress();
                
                // Update estimated time for remaining devices
                var remaining = resumeData.remaining || 0;
                if (remaining > 0) {
                    var estimatedSeconds = Math.ceil((remaining / devicesPerMinute) * 60);
                    updateEstimatedTime(estimatedSeconds, remaining);
                }
            }
        });

        eventSource.addEventListener('output', function(e) {
            var data = e.data;
            // Unescape newlines
            data = data.replace(/\\n/g, '\n');
            
            // Handle RESUME_INFO control message
            var resumeInfoMatch = data.match(/RESUME_INFO:(\d+):(\d+):(\d+)/);
            if (resumeInfoMatch) {
                totalDevices = parseInt(resumeInfoMatch[1]) || 0;
                processedDevices = parseInt(resumeInfoMatch[2]) || 0;
                var remaining = parseInt(resumeInfoMatch[3]) || 0;
                
                if (totalDevices > 0) {
                    $('#sync-progress').removeClass('hide');
                    $('#sync-progress .progress-bar').attr('aria-valuemax', totalDevices);
                    updateProgress();
                    
                    // Update estimated time for remaining devices
                    if (remaining > 0) {
                        var estimatedSeconds = Math.ceil((remaining / devicesPerMinute) * 60);
                        updateEstimatedTime(estimatedSeconds, remaining);
                    }
                }
                
                // Remove RESUME_INFO from output
                data = data.replace(/RESUME_INFO:\d+:\d+:\d+\n?/g, '');
            }
            
            // Remove ESTIMATED_TIME control messages from output (we calculate on frontend now)
            data = data.replace(/ESTIMATED_TIME:\d+:\d+\n?/g, '');
            
            // Only append if there's still content
            if (data.trim().length > 0) {
                appendOutput(data);
            }
            
            // Parse progress information
            // Extract total devices count from "Found X devices" message (appears early)
            var foundMatch = data.match(/Found\s+(\d+)\s+devices/i);
            if (foundMatch) {
                // Only set if we haven't already set it from resume
                if (totalDevices === 0) {
                    totalDevices = parseInt(foundMatch[1]) || 0;
                    if (totalDevices > 0) {
                        $('#sync-progress').removeClass('hide');
                        $('#sync-progress .progress-bar').attr('aria-valuemax', totalDevices);
                        updateProgress();
                    }
                }
            }
            
            // Also check "Total devices" message (appears at end)
            var totalMatch = data.match(/Total devices:\s*(\d+)/i);
            if (totalMatch) {
                totalDevices = parseInt(totalMatch[1]) || totalDevices;
                if (totalDevices > 0 && $('#sync-progress').hasClass('hide')) {
                    $('#sync-progress').removeClass('hide');
                    $('#sync-progress .progress-bar').attr('aria-valuemax', totalDevices);
                }
            }
            
            // Extract device index from heartbeat messages
            // Heartbeat shows absolute position (e.g., "Processing device 150 of 700")
            var heartbeatMatch = data.match(/Processing device\s+(\d+)\s+of\s+(\d+)/i);
            if (heartbeatMatch) {
                var heartbeatPosition = parseInt(heartbeatMatch[1]) || 0;
                var heartbeatTotal = parseInt(heartbeatMatch[2]) || totalDevices;
                
                // Heartbeat position is 1-indexed, so processed = position - 1
                processedDevices = Math.max(processedDevices, heartbeatPosition - 1);
                totalDevices = heartbeatTotal || totalDevices;
                
                if (totalDevices > 0 && $('#sync-progress').hasClass('hide')) {
                    $('#sync-progress').removeClass('hide');
                    $('#sync-progress .progress-bar').attr('aria-valuemax', totalDevices);
                }
                updateProgress();
            }
            
            // Track completed devices - look for result messages (OK, SKIP, ERROR)
            // These appear after "Processing..." messages
            var resultMatch = data.match(/\b(OK|SKIP|ERROR)\s*\(/);
            if (resultMatch) {
                // Only increment if we haven't already counted this device
                // (to avoid double counting on resume)
                if (processedDevices < totalDevices) {
                    processedDevices++;
                    updateProgress();
                }
            }
            
            // Track errors from output messages
            if (data.indexOf('ERROR') !== -1 || data.indexOf('error') !== -1) {
                // Count error lines (not just increment once per message)
                var errorMatches = data.match(/ERROR/gi);
                if (errorMatches) {
                    errorCount += errorMatches.length;
                }
            }
        });

        eventSource.addEventListener('error', function(e) {
            var data = e.data;
            // Unescape newlines
            data = data.replace(/\\n/g, '\n');
            appendOutput('ERROR: ' + data);
            errorCount++;
        });

        eventSource.addEventListener('complete', function(e) {
            var data = JSON.parse(e.data);
            appendOutput('Exit code: ' + data.exit_code + '\n');
            appendOutput('================================================\n');
            
            $status.text(data.success ? 'Finished' : 'Finished with errors');
            showCompletionMessage(data.success, data);
            
            // Reset auto-resume state on successful completion
            resetAutoResumeState();
            
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
            
            stopSync();
        });

        var autoResumeAttempts = 0;
        var maxAutoResumeAttempts = 10; // Max 10 auto-resume attempts
        var autoResumeDelay = 5000; // 5 seconds delay before auto-resume
        var autoResumeStartTime = null; // Track when auto-resume sequence started
        var lastProcessedCount = 0; // Track last processed device count
        var lastFailureTime = null; // Track time of last failure
        var maxAutoResumeDuration = 3600000; // Max 1 hour of auto-resuming (in milliseconds)
        var minTimeBetweenFailures = 30000; // Min 30 seconds between failures to count as progress
        
        function resetAutoResumeState() {
            autoResumeAttempts = 0;
            autoResumeStartTime = null;
            lastProcessedCount = 0;
            lastFailureTime = null;
        }
        
        function attemptAutoResume() {
            var now = Date.now();
            
            // Initialize auto-resume start time on first attempt
            if (autoResumeStartTime === null) {
                autoResumeStartTime = now;
            }
            
            // Check if we've been auto-resuming for too long
            if (now - autoResumeStartTime > maxAutoResumeDuration) {
                appendOutput('\nAuto-resume stopped: Maximum duration (1 hour) exceeded. Please run sync manually.\n');
                $status.text('Auto-resume timeout');
                showCompletionMessage(false, {message: 'Auto-resume stopped after 1 hour. Please run sync manually.'});
                stopSync();
                resetAutoResumeState();
                return;
            }
            
            // Check if we're making progress (processed count increased)
            if (lastFailureTime !== null) {
                var timeSinceLastFailure = now - lastFailureTime;
                var currentProcessed = processedDevices || 0;
                
                // If we're failing too quickly and not making progress, stop
                if (timeSinceLastFailure < minTimeBetweenFailures && currentProcessed <= lastProcessedCount) {
                    appendOutput('\nAuto-resume stopped: No progress detected between failures. Please check connection and run sync manually.\n');
                    $status.text('Auto-resume stopped');
                    showCompletionMessage(false, {message: 'Auto-resume stopped: No progress detected. Please run sync manually.'});
                    stopSync();
                    resetAutoResumeState();
                    return;
                }
                
                // Update last processed count if we made progress
                if (currentProcessed > lastProcessedCount) {
                    lastProcessedCount = currentProcessed;
                }
            } else {
                lastProcessedCount = processedDevices || 0;
            }
            
            // Check max attempts
            if (autoResumeAttempts >= maxAutoResumeAttempts) {
                appendOutput('\nMaximum auto-resume attempts (' + maxAutoResumeAttempts + ') reached. Please run sync manually.\n');
                $status.text('Auto-resume failed');
                showCompletionMessage(false, {message: 'Connection failed after ' + maxAutoResumeAttempts + ' auto-resume attempts. Please run sync manually.'});
                stopSync();
                resetAutoResumeState();
                return;
            }
            
            autoResumeAttempts++;
            lastFailureTime = now;
            appendOutput('\nAuto-resuming sync in ' + (autoResumeDelay / 1000) + ' seconds... (attempt ' + autoResumeAttempts + '/' + maxAutoResumeAttempts + ')\n');
            $status.text('Auto-resuming...');
            
            setTimeout(function() {
                // Only auto-resume if connection is still closed (not manually stopped)
                if (!$btn.prop('disabled') || $status.text() === 'Finished' || $status.text() === 'Finished with errors') {
                    // User manually stopped or sync completed, don't auto-resume
                    resetAutoResumeState();
                    return;
                }
                appendOutput('Resuming now...\n');
                // Close old connection if still exists
                if (eventSource) {
                    eventSource.close();
                    eventSource = null;
                }
                startSync();
            }, autoResumeDelay);
        }

        eventSource.onerror = function(e) {
            if (eventSource.readyState === EventSource.CLOSED) {
                // Connection closed - sync completed or error occurred
                if ($status.text() === 'Running…') {
                    // Unexpected closure - attempt auto-resume
                    appendOutput('\nConnection closed unexpectedly.\n');
                    appendOutput('Progress has been saved. Attempting to auto-resume...\n');
                    attemptAutoResume();
                } else {
                    // Normal completion or user stopped
                    stopSync();
                }
            } else {
                // Connection error - attempt auto-resume
                appendOutput('\nConnection error occurred.\n');
                appendOutput('Progress has been saved. Attempting to auto-resume...\n');
                attemptAutoResume();
            }
        };
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
                    
                    // Close the EventSource connection
                    if (eventSource) {
                        eventSource.close();
                        eventSource = null;
                    }
                    
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