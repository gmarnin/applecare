<?php $this->view('partials/head')?>

<div class="container">
    <div class="row">
        <div class="col-lg-8">
            <h3>AppleCare Admin</h3>
            <p>Run the AppleCare sync script and inspect the output.</p>

            <div class="form-group">
                <button id="sync-applecare" class="btn btn-primary">
                    <i class="fa fa-refresh"></i> Run AppleCare Sync
                </button>
                <span id="sync-status" class="text-muted" style="margin-left:8px;"></span>
            </div>

            <div class="panel panel-default">
                <div class="panel-heading">
                    <strong>Sync Output</strong>
                </div>
                <div class="panel-body">
                    <pre id="sync-output" style="white-space:pre-wrap;min-height:120px;">Waiting to run…</pre>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    var $btn = $('#sync-applecare');
    var $status = $('#sync-status');
    var $output = $('#sync-output');

    function setOutput(text){
        $output.text(text || '');
    }

    $btn.on('click', function(){
        $btn.prop('disabled', true);
        $status.text('Running…');
        setOutput('Running sync...');

        $.ajax({
            url: appUrl + '/module/applecare/sync',
            method: 'POST',
            dataType: 'json'
        }).done(function(data){
            var text = '';
            text += 'Success: ' + (data.success ? 'yes' : 'no') + '\n';
            if (typeof data.exit_code !== 'undefined') {
                text += 'Exit code: ' + data.exit_code + '\n';
            }
            if (data.stdout) {
                text += '\n--- STDOUT ---\n' + data.stdout + '\n';
            }
            if (data.stderr) {
                text += '\n--- STDERR ---\n' + data.stderr + '\n';
            }
            setOutput(text);
            $status.text(data.success ? 'Finished' : 'Finished with errors');
        }).fail(function(jq, textStatus, errorThrown){
            setOutput('Request failed: ' + textStatus + (errorThrown ? ' (' + errorThrown + ')' : ''));
            $status.text('Failed');
        }).always(function(){
            $btn.prop('disabled', false);
        });
    });
})();
</script>

<?php $this->view('partials/foot'); ?>