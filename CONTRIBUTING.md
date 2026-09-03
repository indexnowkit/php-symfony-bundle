# Contributing

This repository is a read-only split of [`indexnowkit/php`](https://github.com/indexnowkit/php) (`packages/symfony-bundle`).
Please open issues and pull requests there; releases are tagged in the monorepo as `symfony-bundle@x.y.z` and mirrored here.

Quick rules (details in the monorepo's CONTRIBUTING.md):

- Every configuration node gets its compile-time validation, a line in `docs/configuration.md` and a functional test
  (`tests/Functional/`, kernel variants in `tests/App/TestKernel.php`).
- Nothing may throw from `kernel.terminate`, a Doctrine flush or a Messenger handler into the application: log on the
  `indexnow` channel instead.
- Symfony 6.4 and the current major both stay green; the Flex recipe in `recipe/` follows every new node.
- phpstan level 9 and php-cs-fixer must pass.
