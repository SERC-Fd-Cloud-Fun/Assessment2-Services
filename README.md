# Cloud File Upload System

PHP-based web application for uploading files to Azure Storage.

## Usage

You can deploy this project to a Debian or Ubuntu Linux VM in either of these ways.

### Option 1: Run the setup script on the VM

Use this option when you have already copied the project files to the VM and want to install Apache and PHP from the project folder.

1. Copy the repository contents to the VM.
2. Connect to the VM with SSH.
3. Change into the project directory that contains `index.php` and `setup-web.sh`.
4. Make the script executable and run it:

```bash
chmod +x setup-web.sh
./setup-web.sh
```

The script will:

- update package lists
- install Apache, PHP, and the Apache PHP module
- write `AZURE_STORAGE_CONNECTION_STRING` into `/etc/apache2/conf-available/azure-storage-env.conf`
- enable and restart Apache
- copy `index.php` to `/var/www/html/index.php`

To set the Azure Storage connection string when using the setup script, export it before running the script:

```bash
export AZURE_STORAGE_CONNECTION_STRING="<your-connection-string>"
./setup-web.sh
```

If you need to change it later on the VM, edit `/etc/apache2/conf-available/azure-storage-env.conf` and then restart Apache:

```bash
sudo nano /etc/apache2/conf-available/azure-storage-env.conf
sudo systemctl restart apache2
```

After the script finishes, open `http://<your-vm-public-ip>/` in a browser.

### Option 2: Use cloud-init during VM creation

Use this option when creating a new VM and you want the web server configured automatically on first boot.

1. Create the Linux VM.
2. Provide the contents of `cloud-init.yml` as the VM custom data or cloud-init configuration.
3. Wait for first-boot provisioning to complete.
4. Browse to `http://<your-vm-public-ip>/`.

The cloud-init configuration will:

- update packages
- install Apache, PHP, and the Apache PHP module
- create `/etc/apache2/conf-available/azure-storage-env.conf`
- create `/var/www/html/index.php`
- create `/var/www/html/uploads`
- enable and restart Apache

Before creating the VM, edit `cloud-init.yml` and replace the empty value in the `write_files` entry for `/etc/apache2/conf-available/azure-storage-env.conf`:

```yaml
write_files:
	- path: /etc/apache2/conf-available/azure-storage-env.conf
		content: |
			SetEnv AZURE_STORAGE_CONNECTION_STRING "<your-connection-string>"
```

If the VM is already running, update `/etc/apache2/conf-available/azure-storage-env.conf` directly on the VM and restart Apache:

```bash
sudo nano /etc/apache2/conf-available/azure-storage-env.conf
sudo systemctl restart apache2
```

Note: `cloud-init.yml` contains its own embedded PHP page content. If you want cloud-init to deploy the repository's `index.php` exactly, update the `write_files` section in `cloud-init.yml` to match that file.

## License

This project is licensed under the MIT License. See [LICENSE](LICENSE) for details.
