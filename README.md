# Open Day Registration — Document Upload

A PHP web application for a college open day registration system. Visitors upload supporting documents (identification, qualifications, portfolio materials) before attending interviews. Files are stored in Azure Blob Storage.

## Features

- File upload form with server-side validation (MIME type, extension, 10 MB size cap)
- Accepts PDF, JPG, PNG, and GIF
- System status panel showing web server hostname and Azure Storage connectivity
- Upload button disabled automatically when storage is unavailable

## Files

| File | Purpose |
|------|---------|
| `index.php` | Main application — upload form and status checks |
| `composer.json` | PHP dependency declaration (`microsoft/azure-storage-blob`) |
| `setup.sh` | Bash provisioning script for an existing Ubuntu VM |
| `cloud-init.yaml` | Cloud-init config to provision a new Azure VM at creation time |

## Requirements

- PHP 8.x with extensions: `curl`, `fileinfo`, `json`, `mbstring`, `xml`, `zip`
- Apache 2.4 with `mod_php`
- [Composer](https://getcomposer.org/)
- An Azure Storage account

## Environment Variable

The application reads the storage connection string from:

```
AZURE_STORAGE_CONNECTION_STRING=DefaultEndpointsProtocol=https;AccountName=...;AccountKey=...;EndpointSuffix=core.windows.net
```

This is set via Apache's `SetEnv` directive in `/etc/apache2/conf-available/openday-env.conf`, which is included by the virtual host configuration.

## Deployment

### Option 1 — Provision a new Azure VM with cloud-init

1. Replace the connection string placeholder in `cloud-init.yaml`:

   ```bash
   sed -i 's|REPLACE_WITH_CONNECTION_STRING|<your-connection-string>|' cloud-init.yaml
   ```

2. Create the VM:

   ```bash
   az vm create \
     --resource-group <resource-group> \
     --name openday-vm \
     --image Ubuntu2404 \
     --custom-data @cloud-init.yaml \
     --generate-ssh-keys \
     --public-ip-sku Standard \
     --size Standard_B1s
   ```

Cloud-init installs Apache2, PHP, Composer, and the application automatically on first boot.

### Option 2 — Run setup.sh on an existing Ubuntu VM

Copy the project to the VM, then run:

```bash
sudo bash setup.sh --connection-string "<your-connection-string>"
```

The script installs all dependencies, deploys the app to `/var/www/openday/`, and configures Apache.

### Updating the connection string after deployment

Edit `/etc/apache2/conf-available/openday-env.conf` on the VM, then restart Apache:

```bash
sudo nano /etc/apache2/conf-available/openday-env.conf
sudo systemctl restart apache2
```

## License

This project is licensed under the MIT License. See [LICENSE](LICENSE) for details.
