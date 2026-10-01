<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>QZ Tray ESC/POS Preview</title>
    <script src="https://cdn.jsdelivr.net/npm/qz-tray/qz-tray.js"></script>
</head>
<body>

<h2>ESC/POS Preview (via PDF Printer)</h2>
<button onclick="printPreview()">Print Preview</button>

<pre id="log"></pre>

<script>
    async function printPreview() {
        try {
            log('Connecting to QZ Tray...');

            if (!qz.websocket.isActive()) {
                await qz.websocket.connect();
            }

            const printers = await qz.printers.find();
            log('Available printers:\n' + printers.join('\n'));

            // 🔑 اختر طابعة PDF
            const printerName =
                printers.find(p => p.toLowerCase().includes('pdf')) ||
                printers[0];

            log('Using printer: ' + printerName);

            const config = qz.configs.create(printerName, {
                copies: 1,
                encoding: 'UTF-8'
            });

            // ESC/POS sample
            const data = [
                '\x1B\x40',              // init
                '\x1B\x61\x01',          // center
                '*** KITCHEN ORDER ***\n',
                '\x1B\x61\x00',          // left
                '----------------------\n',
                'Burger x2\n',
                '  - Extra cheese\n',
                'Fries x1\n',
                '\n',
                'Thank you\n',
                '\n\n',
                '\x1D\x56\x00'           // cut
            ];

            await qz.print(config, data);
            log('Print job sent successfully');

        } catch (err) {
            console.error(err);
            log('ERROR: ' + err);
        }
    }

    function log(msg) {
        document.getElementById('log').textContent += msg + '\n';
    }
</script>

</body>
</html>
