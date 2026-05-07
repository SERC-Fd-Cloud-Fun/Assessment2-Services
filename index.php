<?php
require_once __DIR__ . '/vendor/autoload.php';

use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MicrosoftAzure\Storage\Common\Exceptions\ServiceException;

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------
define('STORAGE_CONTAINER', 'registrations');
define('MAX_FILE_SIZE_MB', 10);
define('ALLOWED_TYPES', ['application/pdf', 'image/jpeg', 'image/png', 'image/gif']);
define('ALLOWED_EXTENSIONS', ['pdf', 'jpg', 'jpeg', 'png', 'gif']);

// ---------------------------------------------------------------------------
// Status checks
// ---------------------------------------------------------------------------
$hostname = gethostname() ?: 'Unknown';

$connectionString = getenv('AZURE_STORAGE_CONNECTION_STRING');
$storageOk        = false;
$storageMessage   = '';

if (empty($connectionString)) {
    $storageMessage = 'Environment variable AZURE_STORAGE_CONNECTION_STRING is not set.';
} else {
    try {
        $blobClient = BlobRestProxy::createBlobService($connectionString);
        // Lightweight probe: list containers (returns at most 1 result)
        $blobClient->listContainers(['MaxResults' => 1]);
        $storageOk      = true;
        $storageMessage = 'Connected successfully.';
    } catch (ServiceException $e) {
        $storageMessage = 'Storage error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    } catch (Exception $e) {
        $storageMessage = 'Connection error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    }
}

// ---------------------------------------------------------------------------
// Handle file upload
// ---------------------------------------------------------------------------
$uploadResult  = null; // 'success' | 'error'
$uploadMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['document'])) {
    $file = $_FILES['document'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $uploadResult  = 'error';
        $uploadMessage = 'Upload failed with error code ' . (int) $file['error'] . '.';
    } elseif ($file['size'] > MAX_FILE_SIZE_MB * 1024 * 1024) {
        $uploadResult  = 'error';
        $uploadMessage = 'File exceeds the ' . MAX_FILE_SIZE_MB . ' MB size limit.';
    } else {
        // Validate MIME type via finfo (more reliable than $_FILES['type'])
        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($mimeType, ALLOWED_TYPES, true) || !in_array($ext, ALLOWED_EXTENSIONS, true)) {
            $uploadResult  = 'error';
            $uploadMessage = 'Unsupported file type. Please upload a PDF or image (JPG, PNG, GIF).';
        } elseif (!$storageOk) {
            $uploadResult  = 'error';
            $uploadMessage = 'Cannot upload: storage is not available.';
        } else {
            // Build a safe, unique blob name
            $safeName   = preg_replace('/[^a-zA-Z0-9.\-_]/', '_', basename($file['name']));
            $blobName   = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $safeName;
            $fileHandle = fopen($file['tmp_name'], 'rb');

            try {
                // Ensure the container exists
                try {
                    $blobClient->createContainer(STORAGE_CONTAINER);
                } catch (ServiceException $e) {
                    // 409 Conflict means it already exists — that is fine
                    if ($e->getCode() !== 409) {
                        throw $e;
                    }
                }

                $blobClient->createBlockBlob(
                    STORAGE_CONTAINER,
                    $blobName,
                    $fileHandle,
                    null  // use default CreateBlobOptions
                );

                fclose($fileHandle);
                $uploadResult  = 'success';
                $uploadMessage = 'Your document &ldquo;' . htmlspecialchars($safeName, ENT_QUOTES, 'UTF-8')
                    . '&rdquo; has been uploaded successfully.';
            } catch (ServiceException $e) {
                if (is_resource($fileHandle)) {
                    fclose($fileHandle);
                }
                $uploadResult  = 'error';
                $uploadMessage = 'Upload failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Open Day Registration &ndash; Document Upload</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: system-ui, Arial, sans-serif;
            background: #f4f6f9;
            color: #222;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        header {
            background: #003366;
            color: #fff;
            padding: 1.2rem 2rem;
        }
        header h1  { font-size: 1.4rem; font-weight: 700; }
        header p   { font-size: 0.9rem; margin-top: 0.25rem; opacity: 0.85; }

        main {
            flex: 1;
            max-width: 700px;
            width: 100%;
            margin: 2rem auto;
            padding: 0 1rem;
        }

        /* Status panel */
        .status-panel {
            background: #fff;
            border: 1px solid #dde3ec;
            border-radius: 6px;
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
        }
        .status-panel h2 {
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #666;
            margin-bottom: 0.75rem;
        }
        .status-row {
            display: flex;
            align-items: flex-start;
            gap: 0.6rem;
            font-size: 0.9rem;
            padding: 0.35rem 0;
            border-top: 1px solid #f0f0f0;
        }
        .status-row:first-of-type { border-top: none; }
        .status-label { font-weight: 600; min-width: 130px; flex-shrink: 0; }
        .badge {
            display: inline-block;
            padding: 0.15rem 0.55rem;
            border-radius: 12px;
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            flex-shrink: 0;
        }
        .badge-ok  { background: #d4edda; color: #155724; }
        .badge-err { background: #f8d7da; color: #721c24; }
        .status-detail { color: #555; }

        /* Upload card */
        .card {
            background: #fff;
            border: 1px solid #dde3ec;
            border-radius: 6px;
            padding: 1.5rem 1.25rem;
        }
        .card h2 { font-size: 1.1rem; margin-bottom: 0.5rem; }
        .card p  { font-size: 0.9rem; color: #555; margin-bottom: 1.25rem; }

        label[for="document"] {
            display: block;
            font-weight: 600;
            margin-bottom: 0.4rem;
            font-size: 0.9rem;
        }
        .hint {
            font-size: 0.8rem;
            color: #777;
            margin-bottom: 0.75rem;
        }
        input[type="file"] {
            display: block;
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #ccd3de;
            border-radius: 4px;
            background: #fafbfc;
            font-size: 0.9rem;
            margin-bottom: 1rem;
        }
        button[type="submit"] {
            background: #003366;
            color: #fff;
            border: none;
            padding: 0.65rem 1.5rem;
            border-radius: 4px;
            font-size: 0.95rem;
            cursor: pointer;
        }
        button[type="submit"]:hover  { background: #00275a; }
        button[type="submit"]:active { background: #001d42; }
        button[type="submit"]:disabled {
            background: #8fa8c8;
            cursor: not-allowed;
        }

        /* Alert messages */
        .alert {
            padding: 0.85rem 1rem;
            border-radius: 4px;
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error   { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        footer {
            text-align: center;
            font-size: 0.78rem;
            color: #999;
            padding: 1.5rem;
        }
    </style>
</head>
<body>

<header>
    <h1>Open Day Registration</h1>
    <p>Document Upload Portal</p>
</header>

<main>

    <!-- System status -->
    <div class="status-panel">
        <h2>System Status</h2>

        <div class="status-row">
            <span class="status-label">Web server</span>
            <span class="badge badge-ok">OK</span>
            <span class="status-detail"><?= htmlspecialchars($hostname, ENT_QUOTES, 'UTF-8') ?></span>
        </div>

        <div class="status-row">
            <span class="status-label">Azure Storage</span>
            <?php if ($storageOk): ?>
                <span class="badge badge-ok">OK</span>
            <?php else: ?>
                <span class="badge badge-err">Error</span>
            <?php endif; ?>
            <span class="status-detail"><?= $storageMessage ?></span>
        </div>
    </div>

    <!-- Upload form -->
    <div class="card">
        <h2>Upload Supporting Document</h2>
        <p>
            Please upload any supporting documents required for your interview
            (e.g. identification, qualifications, or portfolio materials).
            Accepted formats: PDF, JPG, PNG, GIF &mdash; up to <?= MAX_FILE_SIZE_MB ?> MB.
        </p>

        <?php if ($uploadResult === 'success'): ?>
            <div class="alert alert-success"><?= $uploadMessage ?></div>
        <?php elseif ($uploadResult === 'error'): ?>
            <div class="alert alert-error"><?= $uploadMessage ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="MAX_FILE_SIZE"
                   value="<?= MAX_FILE_SIZE_MB * 1024 * 1024 ?>">

            <label for="document">Supporting document</label>
            <span class="hint">PDF, JPG, PNG or GIF &mdash; maximum <?= MAX_FILE_SIZE_MB ?> MB</span>
            <input type="file" id="document" name="document"
                   accept=".pdf,.jpg,.jpeg,.png,.gif" required>

            <button type="submit" <?= $storageOk ? '' : 'disabled' ?>>
                Upload Document
            </button>
            <?php if (!$storageOk): ?>
                <p class="hint" style="margin-top:0.5rem;">
                    Upload is disabled because storage is unavailable.
                </p>
            <?php endif; ?>
        </form>
    </div>

</main>

<footer>
    &copy; <?= date('Y') ?> College Open Day Registration System
</footer>

</body>
</html>
