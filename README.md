# Ordryx for WooCommerce

The WordPress plugin that connects a WooCommerce store to its
[Ordryx](https://ordryx.com): the store's orders flow in to plan, make and
ship them, and orders shipped in Ordryx are marked shipped in the store.

- **To install it,** download the newest zip from
  [ordryx.com/woocommerce](https://ordryx.com/woocommerce) (or from Ordryx:
  Sales › Sales channels › WooCommerce) and upload it under Plugins › Add
  Plugin › Upload Plugin. That page also says how to update it and connect
  the store.
- **What it does, what it sends and when:** [readme.txt](readme.txt), the
  readme WordPress shows. Every call to Ordryx's server is listed under
  "External services".

## This repository

This is the plugin's source as released, one commit per version, copied here
from Ordryx's own repository, where it's built and tested with Ordryx's
server. So a change made here directly would be overwritten by the next
release.

- **Found a bug, or something unclear?** Open an issue.
- **A change you'd like?** Open an issue first, or a pull request to show
  it: a change that's taken goes in through Ordryx's repository, credited to
  you, and arrives here with the next release.
- **A security problem?** Never in an issue: see [SECURITY.md](SECURITY.md).

## Running its tests

PHP 7.4 or newer and [Composer](https://getcomposer.org):

```bash
composer install
vendor/bin/phpunit                                 # the tests (Brain Monkey: no WordPress needed)
vendor/bin/phpcs                                   # WordPress's coding standards
vendor/bin/phpstan analyse --memory-limit=1G       # static analysis
```

## License

GPLv2 or later, as WordPress is: [LICENSE](LICENSE).
