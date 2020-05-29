# Git Webhooks Auto Deployment

Every time a webhook of your choice calls its endpoint, the deploy script automatically pulls latest changes and runs custom post-deploy hooks if defined.

Supported providers include GitHub, GitLab as well as Gitea instances. The script may also be called directly via URL, e.g. `https://example.com/deploy.php?secret=WEBHOOKSECRET`.

The script handles errors well. If no secret is given, a hash comparison fails, etc., a corresponding error is returned. You can insepect it in your webhook's delivery response and the log besides the deploy script.

## Prerequisites

- Git obviously
- PHP 7.0+ (no modules needed)

## Usage

- Copy `deploy.php` into a folder accessible from the web
- Edit the default secret and configuration to your needs
- Make sure your project's `.git` folder including it s content is owned by `www-data`
  - If not, run `sudo chmod -R www-data:www-data .git`
- Add the deploy key as well as the `knwon_hosts` file of your repository to `www-data`'s home directory, defaulting to `/var/www/.ssh`

**Important Note**

If the user for deploy execution is `www-data`, make sure to run `sudo -u www-data git pull` once. Otherwise the webhook will always fail, since the host has not been added to the authorized keys list.

### Options

| Option | Default | Description |
| --- | --- | --- |
| `secret` | *(empty)* | Secret (or token) which the webhook is secured with.
| `directory` | `/var/www/example.com` | Default git repository location.
| `work_dir` | `false` | Your project's work directory. If git and work directories aren't seperated, set it to `false`.
| `log` | `deploy.log` | Relative or absolute path where to save the log file. If set to `false` no log file will be saved.
| `branch` | `master` | Indicates which branch to checkout.
| `remote` | `origin` | Indicates which remote repository to be fetched.
| `date_format` | `Y-m-d H:i:sP` | Indicates the date format of your log file.
| `syncSubmodule` | `false` | Set to `true` if any submodules shall be pulled with each deploy.
| `reset` | `false` | If set to true, runs `git reset --hard` every time you deploy.
| `git_bin_path` | `/usr/bin/git` | Path to `git` executable.

### Post-Deploy Hooks

From line 93 on you may define custom instructions to run after each deployment process.

Example for `katapult-wald.de`, to clear the pages' cache:
```php
$deploy->post_deploy = function () use ($options, $deploy) {
    exec('rm -rf ' . $options['directory'] . '/storage/cache/example.com');
    $deploy->log('Flushing Kirby cache…');
};
```

## Credits

Big thanks to Brandon Summers' blog post [Using Bitbucket for Automated Deployments](http://brandonsummers.name/blog/2012/02/10/using-bitbucket-for-automated-deployments/).
