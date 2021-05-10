# mezzio-migration

[![Build Status](https://github.com/mezzio/mezzio-migration/actions/workflows/continuous-integration.yml/badge.svg)](https://github.com/mezzio/mezzio-migration/actions/workflows/continuous-integration.yml)

This library provides a tool for migrating from Mezzio v2 to v3.

## Installation

Run the following to install this library:

```console
$ composer require --dev mezzio/mezzio-migration
```

## Usage

Once you have installed the tool, execute it with the following:

```bash
$ ./vendor/bin/mezzio-migration migrate
```

> ### Cloning versus composer installation
>
> If you'd rather clone the tooling once and re-use it many times, you can do
> that instead. Clone using:
>
> ```bash
> $ git clone https://github.com/mezzio/mezzio-migration
> ```
>
> And then, instead of using `./vendor/bin/mezzio-migration migrate`, use
> `/full/path/to/mezzio-migration/bin/mezzio-migration`.

> **TODO:**
>
> Our goal is to prepare a downloadable [phar](http://php.net/phar) file that
> can be installed in your system and re-used; this change will come at a future
> date.

## Requirements

All external packages used within your project must be compatible with
Mezzio v3 libraries. If you are unsure, check their dependencies.

This script will uninstall all dependent packages and then will try to install
them with the latest compatible version. In case any package is not compatible,
the script will report an error indicating which package need to be updated.

The following table indicates Mezzio package versions compatible with
version 3, and to which the migration tool will update.

| Package name                                      | Version |
| ------------------------------------------------- | ------- |
| laminas-auradi-config                                | 1.0.0   |
| laminas-component-installer                          | 2.1.0   |
| laminas-config-aggregator                            | 1.1.0   |
| laminas-diactoros                                    | 1.7.1   |
| mezzio                                   | 3.0.0   |
| mezzio-aurarouter                        | 3.0.0   |
| mezzio-authentication                    | 0.4.0   |
| mezzio-authentication-basic              | 0.3.0   |
| mezzio-authentication-oauth2             | 0.4.0   |
| mezzio-authentication-session            | 0.4.0   |
| mezzio-authentication-laminasauthentication | 0.4.0   |
| mezzio-authorization                     | 0.4.0   |
| mezzio-authorization-acl                 | 0.3.0   |
| mezzio-authorization-rbac                | 0.3.0   |
| mezzio-csrf                              | 1.0.0   |
| mezzio-fastroute                         | 3.0.0   |
| mezzio-flash                             | 1.0.0   |
| mezzio-hal                               | 1.0.0   |
| mezzio-helpers                           | 5.0.0   |
| mezzio-platesrenderer                    | 2.0.0   |
| mezzio-router                            | 3.0.0   |
| mezzio-session                           | 1.0.0   |
| mezzio-session-ext                       | 1.0.0   |
| mezzio-template                          | 2.0.0   |
| mezzio-tooling                           | 1.0.0   |
| mezzio-twigrenderer                      | 2.0.0   |
| mezzio-laminasrouter                        | 3.0.0   |
| mezzio-laminasviewrenderer                  | 2.0.0   |
| laminas-httphandlerrunner                            | 1.0.1   |
| laminas-pimple-config                                | 1.0.0   |
| mezzio-problem-details                              | 1.0.0   |
| laminas-stratigility                                 | 3.0.0   |


## What does the tool do?

In order to operate, the tool requires that the application directory contains a
`composer.json` file, and that this file is writable by the script.

Next, it attempts to detect the currently used Mezzio version. If the
version detected is not a 2.X version, the script will exit without performing
any changes.

It then performs the following steps:

1. Removes the `vendor` directory.

2. Installs current dependencies using `composer install`.

3. Analyzes `composer.lock` to identify all packages which depends on Mezzio packages.

4. Removes all installed Mezzio packages and packages that depend on them.

5. Updates all remaining packages using `composer update`.

6. Requires all Mezzio packages previously installed, adding the packages
   `laminas/laminas-component-installer` and `mezzio/mezzio-tooling`
   as development packages if they were not previously installed.

7. Requires all packages installed previously that were dependent on Mezzio.
   **This step may fail** in situations where external packages are not yet
   compatible with Mezzio v3 or its required libraries.

8. Updates `config/pipeline.php`:
   1. adds strict type declarations to the top of the file;
   2. adds a function wrapper (as is done in the version 3 skeleton);
   3. updates the following middleware:
      - `pipeRoutingMiddleware` becomes a `pipe()` statement referencing `Mezzio\Router\Middleware\RouteMiddleware`.
      - `pipeDispatchMiddleware` becomes a `pipe()` statement referencing `Mezzio\Router\Middleware\DispatchMiddleware`,
      - References to `Mezzio\Middleware\NotFoundHandler` become `Mezzio\Handler\NotFoundHandler`,
      - References to `Mezzio\Middleware\ImplicitHeadMiddleware` become `Mezzio\Router\Middleware\ImplicitHeadMiddleware`,
      - References to `Mezzio\Middleware\ImplicitOptionsMiddleware` become `Mezzio\Router\Middleware\ImplicitOptionsMiddleware`,
   4. pipes `Mezzio\Router\Middleware\MethodNotAllowedMiddleware` after
      `Implicit*Middleware` (or if these are not piped, after
      `Mezzio\Router\Middleware\RouteMiddleware`).

9. Updates `config/routes.php`:
   1. adds strict type declaration on top of the file;
   2. adds a function wrapper (as is done in the version 3 skeleton).

10. Replaces `public/index.php` with the latest version from the v3 skeleton.

11. Updates container configuration if `pimple` or `Aura.Di` were used
    (`config/container.php`) from the latest skeleton version. Additionally, it
    does the following:
    - For `pimple`: the package `xtreamwayz/pimple-container-interop` is replaced by `laminas/laminas-pimple-config`.
    - For `Aura.Di`: the package `aura/di` is replaced by `laminas/laminas-auradi-config`.

12. Migrates http-interop middleware to PSR-15 middleware using
    `./vendor/bin/mezzio migrate:interop-middleware`.

13. Migrates PSR-15 middleware to PSR-15 request handlers using
    `./vendor/bin/mezzio migrate:middleware-to-request-handler`.

14. Runs `./vendor/bin/phpcbf` if it is available.

## What should you do after migration?

You will need to update your tests to use PSR-15 middleware instead of
http-interop middleware.  This step is not done automatically because _it is too
complicated_. We can easily change imported classes, but unfortunately test
strategies and mocking strategies vary widely, and detecting all http-interop
variants makes this even more difficult.

Please manually compare and verify all changes made. It is possible that in some
edge cases, the script will not work correctly. This will depend primarily on
the number of modifications you have made to the original skeleton.

> #### Configuration-driven pipelines and routes
>
> The script does not work currently make any modifications to pipeline and
> route configuration; these will need to be updated manually.
