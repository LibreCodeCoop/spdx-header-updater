# SPDX Header Updater

The **SPDX Header Updater** is a tool designed to facilitate the updating of license headers in source code files, converting them to the SPDX (Software Package Data Exchange) standard. With a single command, you can search for all files that may contain a license header and automatically apply the SPDX format, ensuring that your project complies with best licensing practices.

## Why Use the SPDX Standard?

For detailed information on the benefits of using the SPDX standard, please refer to the official [SPDX Documentation](https://spdx.org/specifications).

## Installation

To install the **SPDX Header Updater**, follow the steps below:

1. **Clone the project repository**:

   ```bash
   git clone https://github.com/librecodecoop/spdx-header-updater.git
   cd spdx-header-updater
   ```

2. **Install the dependencies**:

   ```bash
   composer install
   ```

3. **Check the command options**:

   Run the following command to see the available options:

   ```bash
   php bin/console convert --help
   ```

## Usage

Before applying changes, it is recommended to run the command in dry-run mode (`--dry-run`) to check what changes will be made:

```bash
php bin/console convert --dry-run
```

### Example Usage

To convert the license headers, run the command without the `--dry-run` option:

```bash
php bin/console convert -i vendor <default-file-copyright> <path>
```

Replace `<default-file-copyright>` with the default copyright text you want to use and `<path>` with the directory where the files are located.

## Reviewing Changes

After running the command, review the changes made to the files to ensure everything is as expected. You can version the changes as you wish.

## Create REUSE Compliance Check at GitHub Actions

Create the file `.github/workflows/reuse.yml` with the follow content:

```yaml
# https://docs.github.com/en/actions/learn-github-actions/sharing-workflows-with-your-organization

# SPDX-FileCopyrightText: 2022 Free Software Foundation Europe e.V. <https://fsfe.org>
#
# SPDX-License-Identifier: CC0-1.0

name: REUSE Compliance Check

on: [pull_request]

jobs:
  reuse-compliance-check:
    runs-on: ubuntu-latest
    steps:
      - name: Checkout
        uses: actions/checkout@eef61447b9ff4aafe5dcd4e0bbf5d482be7e7871 # v4.2.1

      - name: REUSE Compliance Check
        uses: fsfe/reuse-action@3ae3c6bdf1257ab19397fab11fd3312144692083 # v4.0.0
```

## License

This project is licensed under the [AGPLv3 License](LICENSE).
