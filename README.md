# arcanist-build-status

An [Arcanist](https://github.com/phacility/arcanist) extension that displays the current authored revisions, branches, and build status info.

This is a combination of `arc list` and `arc feature` with the addition of build status.

## Installation

Clone the repo.

```bash
$ cd path/to/phab/
$ ls
arcanist  libphutil
$ git clone https://github.com/danieliu/arcanist-build-status.git
```

Load the extension globally in `/etc/arcconfig` or on a per project basis in `/project/.arcconfig`.

```json
{
    "load": ["arcanist-build-status"]
}
```

## Usage

```bash
$ arc build-status
  lint-fixes                          Changes Planned    building  D28755: Lint fixes
* test-branch                         Needs Review       failed    D28901: Test branch
  bugfix/ensure-non-null-values       Accepted           passed    D28904: Fixes bug with nulls
  feature/add-custom-response-values  Accepted           passed    D29154: Adds custom response values
```
