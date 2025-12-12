<?php

// Simple utility page to run the AppleCare sync script from the UI.
// - GET renders a page with a Sync button
// - POST (?action=sync) executes sync_applecare.php and returns JSON with the output

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sync') {
    header('Content-Type: application/json');

    $scriptPath = realpath(__DIR__ . '/sync_applecare.php');
    if (! $scriptPath || ! file_exists($scriptPath)) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'sync_applecare.php not found'
        ]);
        exit;
    }

    $phpBin = PHP_BINARY ?: 'php';
    $cmd = escapeshellcmd($phpBin) . ' ' . escapeshellarg($scriptPath);

    $descriptorSpec = [
        1 => ['pipe', 'w'], // stdout
        2 => ['pipe', 'w'], // stderr
    ];

    $process = proc_open($cmd, $descriptorSpec, $pipes, dirname($scriptPath));

    if (! is_resource($process)) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to start sync process'
        ]);
        exit;
    }

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    echo json_encode([
        'success' => $exitCode === 0,
        'exit_code' => $exitCode,
        'stdout' => $stdout,
        'stderr' => $stderr,
    ]);
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>AppleCare Sync</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 2rem; }
        button { padding: 0.5rem 1rem; font-size: 1rem; }
        pre { background: #f5f5f5; border: 1px solid #ddd; padding: 1rem; white-space: pre-wrap; }
    </style>
</head>
<body>
    <h1>AppleCare Sync</h1>
    <p>Click the button below to run <code>sync_applecare.php</code> and see the results.</p>

    <button id="sync-btn">Run Sync</button>

    <h3>Output</h3>
    <pre id="output">Waiting to run…</pre>

    <script>
    (function(){
        var btn = document.getElementById('sync-btn');
        var output = document.getElementById('output');

        btn.addEventListener('click', function(){
            btn.disabled = true;
            output.textContent = 'Running sync...';

            var formData = new FormData();
            formData.append('action', 'sync');

            fetch(window.location.href, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(function(response){
                return response.json();
            })
            .then(function(data){
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
                output.textContent = text;
            })
            .catch(function(err){
                output.textContent = 'Error: ' + err;
            })
            .finally(function(){
                btn.disabled = false;
            });
        });
    })();
    </script>
</body>
</html>
