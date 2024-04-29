<?php

namespace RoboEnv\Robo\Plugin\Commands;

use Cocur\Slugify\Slugify;
use Robo\Robo;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * Run orchestration tasks for Lando.
 *
 * @class RoboFile
 */
class LandoCommands extends \RoboEnv\Robo\Plugin\Commands\CommonCommands
{

    /**
     * The path to the .lando.local.yml.
     *
     * @var string
     */
    protected string $lando_local_yml_path = '.lando.local.yml';

    /**
     * The path to the .lando.yml.
     *
     * @var string
     */
    protected string $lando_yml_path = '.lando.yml';

    /**
     * The path to the .lando.yml.
     *
     * @var string
     */
    protected string $lando_dist_yml_path = '.lando.dist.yml';

    /**
     * The path to Drush.
     *
     * @var string
     */
    protected string $path_to_drush = 'lando drush';

    /**
     * Toggles when Lando starts up, xdebug will now be on by default.
     *
     * When Xdebug is on and xdebug:always-connect has not set XDEBUG_SESSION=1,
     * then you will have to do it manually like ?XDEBUG_SESSION=1 on a URL and
     * ?XDEBUG_SESSION_STOP to stop it.
     *
     *
     * @command lando:xdebug-toggle-on-by-default
     */
    public function xdebugToggleOnByDefault(): void
    {
        $this->isLandoInit();
        $yml_file = $this->getLandoLocalYml();
        $yml_value =& $yml_file['config']['xdebug'];
        if ($yml_value === true) {
            $this->yell('Xdebug is enabled by default, disabling now.');
            $yml_value = false;
            $this->_exec('lando xdebug-off');
        } else {
            $this->yell('Enabling Xdebug by default.');
            $yml_value = true;
            $this->_exec('lando xdebug-on');
        }
        $this->saveLandoLocalYml($yml_file);
    }

    /**
     * Toggles XDebug always starting a debug session without ?XDEBUG_SESSION=1.
     *
     * This is handy if you want to debug CLI or not have to worry about
     * triggering Xdebug to connect.
     *
     * https://xdebug.org/docs/step_debug
     *
     * @command lando:xdebug-toggle-always-connect
     */
    public function xdebugToggleAlwaysConnect(): void
    {
        $this->isLandoInit();
        $yml_file = $this->getLandoLocalYml();
        $yml_value =& $yml_file['services']['appserver']['overrides']['environment']['XDEBUG_SESSION'];
        if ($yml_value === 1) {
            $this->yell('Xdebug is is already connecting by default, disabling so trigger must be passed.');
            unset($yml_file['services']['appserver']['overrides']['environment']);
        } else {
            $this->yell('Allowing Xdebug to connect automatically. Warning: You will see "Step Debug" warnings about Xdebug not being able to connect when running CLI commands if you IDE is not listening.');
            $yml_value = 1;
        }
        $this->saveLandoLocalYml($yml_file);
        $this->rebuildRequired(true);
    }

    /**
     * Initializes and returns the array value of .lando.local.yml.
     *
     * @return array
     */
    protected function getLandoLocalYml(): array
    {
        if (!file_exists($this->lando_local_yml_path)) {
            $this->taskFilesystemStack()->touch($this->lando_local_yml_path)->run();
        }
        return Yaml::parse(file_get_contents($this->lando_local_yml_path)) ?? [];
    }

    /**
     * Returns the array value of .lando.yml.
     *
     * @return array
     *   If 'name' is not set, an empty array will be returned.
     */

    protected function getLandoYml(): array
    {
        $default = [];
        if (!file_exists($this->lando_yml_path)) {
            return $default;
        }
        return Yaml::parse(file_get_contents($this->lando_yml_path)) ?? [];
    }

    /**
     * Returns the array value of .lando.dist.yml.
     *
     * @return array
     *   The array value of the .lando.dist.yml.
     */

    protected function getLandoDistYml(): array
    {
        return Yaml::parse(file_get_contents($this->lando_dist_yml_path));
    }

    /**
     * Requires lando file to exist.
     *
     * @param bool $return
     *   If true, returns false instead of an exception.
     *
     * @return bool
     *
     * @throws \Exception
     */
    protected function doesLandoFileExists(bool $return = false): bool
    {
        if (!file_exists($this->lando_yml_path)) {
            if ($return) {
                return false;
            }
            throw new \Exception('Lando file does not exist, please run lando-admin:setup-project instead.');
        }
        return true;
    }

    /**
     * Is Lando ready to start?
     *
     * @param bool $return
     *   If true, returns false instead of an exception.
     *
     * @return bool
     *
     * @throws \Exception
     */
    protected function isLandoInit(bool $return = false): bool
    {
        if (!$this->doesLandoFileExists($return)) {
            return false;
        }
        $lando_yml = $this->getLandoYml();
        if (empty($lando_yml['name'])) {
            if ($return) {
                return false;
            }
            throw new \Exception("{$this->lando_yml_path} has not set project name yet, please run lando-admin:setup-project instead.");
        }
        return true;
    }

    /**
     * Save the $file_contents to .lando.local.yml.
     *
     * @param array|string $file_contents
     *   A string of yaml or an array.
     *
     * @return bool
     */
    protected function saveLandoLocalYml(array|string $file_contents): bool
    {
        return $this->saveYml($this->lando_local_yml_path, $file_contents);
    }

    /**
     * Save the $file_contents to .lando.yml.
     *
     * @param array|string $file_contents
     *   A string of yaml or an array.
     *
     * @return bool
     */
    protected function saveLandoYml(array|string $file_contents): bool
    {
        $this->say("{$this->lando_yml_path} has been written. Please commit this file so that ./robo lando:init can be run and the environment can be started by yourself and others. If the project has already been started, you will need to lando rebuild -y");
        return $this->saveYml($this->lando_yml_path, $file_contents);
    }

    /**
     * Create a '.lando.site' URL to a given $service with a $protocol.
     *
     * @param string $project_name
     *   The lando project name.
     * @param string $service
     *   The service to create the URL for.
     * @param string $protocol
     *   Either http:// or https://
     *
     * @return string
     */
    protected function getLandoUrl(string $project_name, string $service = 'appserver', string $protocol = 'http://'): string
    {
        // The main service does not have a sub-subdomain.
        if (str_starts_with($service, 'appserver')) {
            $service = '';
        } else {
            $service = "$service.";
        }
        $slugify = new Slugify();
        return sprintf('%s%s%s.lndo.site', $protocol, $service, $slugify->slugify($project_name));
    }

    /**
     * Configure Lando so it can be started.
     *
     * * Creates the .lando.yml file
     * * Set the project name
     * * Set the PHP version
     *
     * @command lando-admin:init
     */
    public function landoAdminInit(): void
    {
        if ($this->doesLandoFileExists(true) && !$this->confirm("Lando is already set up, are you sure you want to update your {$this->lando_yml_path} file?")) {
            $this->say('Cancelled.');
            return;
        }
        $this->ask('Setting the project name. This can be run by itself later via `./robo lando-admin:set-project-name`. Press enter to continue.');
        // A project name must be set.
        $this->_exec('vendor/bin/robo lando-admin:set-project-name')->stopOnFail();

        $this->ask('Setting the recipe automatically to the most optimal. This can be run by itself later via `./robo lando-admin:set-recipe`. Press enter to continue.');
        $this->_exec('vendor/bin/robo lando-admin:set-recipe');

        $this->ask('Setting required shared services. This can be run by itself later via `./robo lando-admin:set-required-shared-services`. Press enter to continue.');
        $this->_exec('vendor/bin/robo lando-admin:set-required-shared-services');

        $this->ask('Lando will now start up and install Drupal so that the scripts can work on your current install.');
        $this->_exec('vendor/bin/robo lando:init')->stopOnFail();

        $this->ask('Setting optional shared services. This can be run by itself later via `./robo lando-admin:set-optional-shared-services`. Press enter to continue.');
        $this->_exec('vendor/bin/robo lando-admin:set-optional-shared-services');

        // The following are shared commands that are not specific to Lando, but
        // instead just need a local installed to work.
        $this->ask('Taking action after a local has been installed. This can be run by itself later via `./robo post-local-started`. Press enter to continue.');
        $this->_exec('vendor/bin/robo common-admin:post-local-started');

    }

    /**
     * Set the lando project name.
     *
     * @command lando-admin:set-project-name
     *
     * @return void
     */
    public function landoAdminSetProjectName(SymfonyStyle $io): void
    {
        $lando_yml = $this->getLandoYml();
        $project_name_set = false;
        if (!empty($lando_yml['name'])) {
            $project_name_set = true;
            $default_project_name = $lando_yml['name'];
            if (!$this->confirm("Your Lando project name is already set as '$default_project_name', would you like to update?")) {
                // Don't throw an exception, a project name was already set.
                $this->yell('Cancelled setting project name.');
                return;
            }
        }
        else {
            $default_project_name = basename(getcwd());
        }
        $slugify = new Slugify();
        $example_project_name = 'sub.great_site';
        $io->note(sprintf('Your project name determines your URL. For example, if your project name is "%s" is, your site URL will be "%s".', $example_project_name,  $this->getLandoUrl($example_project_name)));
        $project_name = $this->askDefault('Choose your project name', $default_project_name);
        if (!strlen($slugify->slugify($project_name))) {
            $message = 'Your project name would result in a zero character URL.';
            if ($project_name_set) {
                $this->yell($message);
                return;
            }
            throw new \Exception($message);
        }
        $project_url = $this->getLandoUrl($project_name);
        if (!$this->confirm("A project name of '$project_name' will result in a URL of '$project_url', do you want to continue?", true)) {
            $message = 'Cancelled setting project name.';
            if ($project_name_set) {
                $this->yell($message);
                return;
            }
            throw new \Exception($message);
        }
        $lando_yml['name'] = $project_name;
        $this->saveLandoYml($lando_yml);
        // This effects the URLs, update them.
        $this->landoSetupUrls();
    }

    /**
     * Set the versions of services provided by default from the Recipe.
     *
     * @param SymfonyStyle $io
     * @param bool $version_only
     * @param string $shared_service_key
     * @param string $config_key
     * @param string $description
     *
     * @return bool|null
     *
     * @throws \Exception
     */
    protected function setRecipeService(SymfonyStyle $io, bool $version_only, string $shared_service_key, string $config_key, string $description): ?bool
    {
        $lando_yml = $this->getLandoYml();
        $lando_dist_yml = $this->getLandoDistYml();
        $dist_value = $lando_dist_yml['config'][$config_key] ?? '';
        $override_value = $lando_yml['config'][$config_key] ?? '';
        if ($version_only) {
            $this->yell(ucfirst($description) . ' is always included in the Drupal recipe, but you can choose the version.');
        } else {
            $this->yell(ucfirst($description) . ' is always included in the Drupal recipe, but you can choose the type and version.');
        }
        $new_service_value = $this->askForService($io, false, false, $dist_value, $override_value, $shared_service_key, $version_only, $description);
        if (strlen($new_service_value)) {
            if (str_starts_with($new_service_value, '!')) {
                unset($lando_yml['config'][$config_key]);
                $this->saveLandoYml($lando_yml);
                return null;
            } else {
                $lando_yml['config'][$config_key] = $new_service_value;
                $this->saveLandoYml($lando_yml);
                return true;
            }
        }
        return false;
    }

    /**
     * Modify the services that are not part of the recipe.
     *
     * @param SymfonyStyle $io
     * @param string $shared_or_personal
     *   Either 'shared' (.lando.yml) or 'personal' (.lando.local.yml).
     * @param string $shared_service_key
     *   The top level key from sharedServices() that is shared between all
     *   different platforms.
     * @param string $services_key
     *   The name lando service name (like 'cache', not 'memcache' or 'redis').
     * @param string $description
     *   Some text that explains better what it is as a replacement.
     *
     * @return bool|string|null
     *   False if nothing changes. True if new service added. Null if service
     *   removed.
     */
    protected function setOptionalService(SymfonyStyle $io, string $shared_or_personal, string $shared_service_key, string $services_key, string $description, string|null &$service_type, string|null &$service_version): null|bool|string
    {
        $_self = $this;
        switch ($shared_or_personal) {
            case 'shared':
                $lando_yml = $this->getLandoYml();
                $save_lando_yml = static function ($lando_yml) use($_self) {
                    $_self->saveLandoYml($lando_yml);
                };
                break;

            case 'personal':
                $lando_yml = $this->getLandoLocalYml();
                $save_lando_yml = static function ($lando_yml) use($_self) {
                    $_self->saveLandoLocalYml($lando_yml);
                };
                break;

            default:
                throw new \InvalidArgumentException('$shared_or_personal must be either "shared" or "personal"');

        }
        $lando_dist_yml = $this->getLandoDistYml();
        $dist_value = $lando_dist_yml['services'][$services_key]['type'] ?? '';
        $override_value = $lando_yml['services'][$services_key]['type'] ?? '';
        $this->yell(ucfirst($description) . ': Optional, feel free not use or remove.');
        $new_service_value = $this->askForService($io, $shared_or_personal === 'personal', true, $dist_value, $override_value, $shared_service_key, false, $description);
        if (strlen($new_service_value)) {
            // Set the new service value to the type & version params so they
            // are known by the caller.
            [$service_type, $service_version] = explode(':', $new_service_value) + ['', ''];
            $service_type = str_replace('!', '', $service_type);
            // Remove the optional service.
            if (str_starts_with($new_service_value, '!')) {
                unset($lando_yml['services'][$services_key]);
                $save_lando_yml($lando_yml);
                return null;
                // Add the optional service.
            } else {
                // Grab additional configuration needed for the Lando service.
                $additional_config = self::sharedServices()[$shared_service_key][$service_type]['additional_config'] ?? [];
                $config = [
                        'type' => $new_service_value,
                    ] + $additional_config;
                $lando_yml['services'][$services_key] = $config;
                $save_lando_yml($lando_yml);
                return true;
            }
        }
        if ($override_value) {
            [$service_type, $service_version] = explode(':', $override_value) + ['', ''];
        } elseif ($dist_value) {
            [$service_type, $service_version] = explode(':', $dist_value) + ['', ''];
        }
        return false;
    }

    /**
     * Prompt a user for a service or service version.
     *
     * @param SymfonyStyle $io
     * @param bool $personal_service
     *   This will only be a local override.
     * @param bool $optional_service
     *   This is not part of the default services in the recipe.
     * @param string $dist_value
     *   The current value rom .lando.dist.yml.
     * @param string $override_value
     *   The current value from .lando.yml.
     * @param string $shared_service_key
     *   The top level key from sharedServices().
     * @param bool $version_only
     *   Only ask for the version, not the type.
     * @param string $description
     *   A helpful description of what the $shared_service_key does.
     *
     * @return string
     *   The full service name with optional version.
     *
     * @throws \Exception
     */
    protected function askForService(SymfonyStyle $io, bool $personal_service, bool $optional_service, string $dist_value, string $override_value, string $shared_service_key, bool $version_only, string $description): string
    {
        $get_default = static function (string $override = '', string $dist = '') use($personal_service, $optional_service): array {
            if (strlen($override)) {
                if ($personal_service) {
                    return [$override, "Personal value of '$override'"];
                }
                return [$override, "Project level override of '$override'"];
            } elseif (strlen($dist)) {
                return [$dist, "Distribution default value of '$dist'"];
            } elseif ($optional_service) {
                return ['', "Not added yet"];
            } else {
                return ['', 'Drupal recipe default.'];
            }
        };

        if ($version_only) {
            $dist_type = '';
            $dist_version = $dist_value;
            $override_type = '';
            $override_version = $override_value;
        } else {
            [$dist_type, $dist_version] = explode(':', $dist_value) + ['', ''];
            [$override_type, $override_version] = explode(':', $override_value) + ['', ''];
        }

        if (!$version_only) {
            $type_default = $get_default($override_type, $dist_type);
            $this->yell("Current type: {$type_default[1]}");
            $options = array_keys(self::sharedServices()[$shared_service_key] ?? []);
            if (empty($options)) {
                throw new \Exception('The $shared_service_key of ' . $shared_service_key . ' does not have any values in ::sharedServices()');
            }
            $options = array_combine($options, $options);
            if ($override_type && $type_default[0] === $override_type) {
                $this->yell('You can only switch types by removing the override first. The other option(s) are ' . implode(', ', array_diff($options, [$override_type]))) . '.';
                $options = ['remove override' => 'Remove Override', $override_type => $override_type];
            }
            $options['cancel'] = 'Cancel / Skip';
            $type_choice = $io->choice("Which $description would you like to use (Setting a version is next, you cannot 'cancel')?", $options, $type_default[0]);

            if ($type_choice === 'cancel') {
                $this->yell(ucfirst($description) . ' type not updated.');
                return '';
            } elseif ($type_choice === 'remove override') {
                $this->yell(ucfirst($description) . " override has been removed.");
                return "!$override_type";
            } else {
                $this->yell(ucfirst($description) . " of $type_choice chosen, please choose version to complete.");
            }
        }

        if (!empty($type_choice) && !empty($type_default[0]) && $type_choice !== $type_default[0]) {
            $version_default = ['', 'Switching type, not applicable.'];
        } else {
            $version_default = $get_default($override_version, $dist_version);
        }
        $this->yell("Current version: {$version_default[1]}");
        if (!$version_only) {
            $options = self::sharedServices()[$shared_service_key][$type_choice]['versions'] ?? [];
            if (empty($options)) {
                throw new \Exception("There are no versions specified for $shared_service_key > $type_choice, if you want to allow the default version please please a 'default' key in the array.");
            }
        } else {
            $options = self::sharedServices()[$shared_service_key][$shared_service_key]['versions'];
            if (empty($options)) {
                throw new \Exception("There are no versions specified for config > $shared_service_key, if you want to allow the default version please please a 'default' key in the array.");
            }
        }
        $options = array_combine($options, $options);
        // This type allows a 'default' option.
        if (false !== $default_key = array_search('default', $options)) {
            // If they've chosen a type that has no options and has a 'default',
            // then set the default version to the default option.
            if (!empty($type_choice)) {
                $version_default[0] = 'default';
            }
            // Change the label and key of the default option.
            unset($options[$default_key]);
            $options['default'] = 'The default version (Unknown, whatever the service decides)';

        }

        if (!empty($type_choice)) {
            $version_label = $type_choice;
        }
        else {
            $version_label = $description;
        }
        $options['cancel'] = 'Cancel / Skip';
        $options['custom'] = 'Enter a custom version...';
        $version_choice = $io->choice("Choose $version_label version", $options, $version_default[0]);
        if ($version_choice === 'custom') {
            $version_choice = $io->ask('Please enter a custom version number (20.1.2, 1.2, etc):', '');
            if (!strlen($version_choice)) {
                $version_choice = 'cancel';
            }
        }
        if ($version_choice === 'cancel') {
            $this->yell(ucfirst($description) . ' version not updated.');
        } else {
            // Some config services only have versions (like PHP).
            if (!empty($type_choice) && strlen($version_choice)) {
                if ('default' === $version_choice) {
                    $final_value = $type_choice;
                } else {
                    $final_value = "$type_choice:$version_choice";
                }
            } else {
                $final_value = $version_choice;
            }
            if ($override_value) {
                if ($override_value === $final_value) {
                    if ($personal_service) {
                        $this->yell(ucfirst($description) . " personal value already set to $final_value");
                    } else {
                        $this->yell(ucfirst($description) . " project override value already set to $final_value");
                    }
                    return '';
                }
            } elseif ($dist_value && $dist_value === $final_value) {
                $this->yell(ucfirst($description) . " distribution value already set to $final_value");
                return '';
            }
            $this->yell(ucfirst($description) . " set to $final_value");
            return $final_value;

        }
        return '';
    }

    /**
     * Change the version of services always included in the Drupal recipe.
     *
     * @command lando-admin:set-required-shared-services
     *
     * @return void
     */
    public function landoAdminSetRequiredSharedServices(InputInterface $input, OutputInterface $output): void
    {

        $this->isLandoInit();
        $io = new SymfonyStyle($input, $output);
        $io->warning('Drupal requirements for Web Servers: https://www.drupal.org/docs/getting-started/system-requirements/web-server-requirements');
        $this->setRecipeService($io, false, 'web', 'via', 'web server');
        $io->warning('Drupal requirements for Databases: https://www.drupal.org/docs/getting-started/system-requirements/database-server-requirements');
        $this->setRecipeService($io, false, 'database', 'database', 'database');
        $io->warning('Drupal requirements for PHP: https://www.drupal.org/docs/getting-started/system-requirements/php-requirements#versionsn');
        $this->setRecipeService($io, true, 'php', 'php', 'PHP');
    }

    /**
     * Add or remove additional shared services.
     *
     * @command lando-admin:set-optional-shared-services
     *
     * @return void
     */
    public function landoAdminSetOptionalSharedServices(InputInterface $input, OutputInterface $output): void
    {
        $this->isLandoInit();
        $this->isDrupalInstalled();
        $io = new SymfonyStyle($input, $output);
        $rebuild_required = false;
        $status = $this->setOptionalService($io, 'shared', 'cache', 'cache', 'cache server', $service_type, $service_version);
        if (false !== $status) {
            $rebuild_required = true;
        }
        $this->reactToSharedService($service_type, $status);
        $status = $this->setOptionalService($io, 'shared','search', 'search', 'search server', $service_type, $service_version);
        $this->reactToSharedService($service_type, $status);
        if (!$rebuild_required && false !== $status) {
            $rebuild_required = true;
            $this->yell('If you are changing the version or the type of the search server, please `lando destroy -y` instead of `lando rebuild -y`');
        }
        $status = $this->setOptionalService($io, 'shared','node', 'node', 'node application', $service_type, $service_version);
        $this->reactToSharedService($service_type, $status);
        if (!$rebuild_required && false !== $status) {
            $rebuild_required = true;
        }
        $this->rebuildRequired($rebuild_required);
    }

    /**
     * Helper to allow for fast rebuild.
     *
     * @param bool $rebuild_required
     *   If true, a "confirm" will be shown to rebuild lando.
     *
     * @return void
     */
    protected function rebuildRequired(bool $rebuild_required, string $confirm_message = ''): void
    {
        if (strlen($confirm_message)) {
            $confirm_message = "A Lando rebuild is required because $confirm_message, please confirm to do so.";
        } else {
            $confirm_message = "A Lando rebuild is required, please confirm to do so.";
        }
        if ($rebuild_required && $this->confirm($confirm_message)) {
            $this->_exec('lando rebuild -y');
        }
    }

    /**
     * Act when a shared service is modified.
     *
     * @param string $service_type
     *   The Lando service name.
     *
     * @param bool|null $status
     *   False if nothing changes. True if new service added. Null if service
     *   removed.
     *
     * @return void
     *
     * @throws \Exception
     */
    protected function reactToSharedService(string $service_type, bool|null $status = false): void
    {
        // Stayed the same.
        if ($status === false) {
            return;
        }
        $add = true;
        // Service is being removed.
        if ($status === null) {
            $add = false;
        }
        $self = $this;
        $add_or_remove_module = static function(string $module_name, array $additional_uninstall = []) use($add, $self): void {
            if ($add) {
                $self->taskComposerRequire('./composer')->dependency('drupal/' . $module_name)->run();
                $self->drush(['en', '-y', $module_name]);
            } else {
                $additional_uninstall[] = $module_name;
                $self->drush(array_merge(['pm-uninstall', '-y'], $additional_uninstall));
                if ($self->confirm('Would you like to remove the drupal/' . $module_name . ' composer dependency right now? Only do so right away if this module is not enabled on production, otherwise, you will get errors when trying to uninstall and removing the dependency at the same time.')) {
                    $self->taskComposerRemove('./composer')->arg('drupal/' . $module_name)->run();
                }
            }
        };
        switch ($service_type) {
            case 'memcached':
                $add_or_remove_module('memcache');
                break;

            case 'redis':
                $add_or_remove_module('redis');
                break;

            case 'solr':
                // Search API gets turned on at the same time as solr, uninstall
                // it too.
                $add_or_remove_module('search_api_solr', ['search_api']);
                if ($add) {
                    $this->yell('IMPORTANT MANUAL CONFIGURATION:');
                    $this->yell("The Search API Solr module has been added and enabled, but you must manually create the core at /admin/config/search/search-api/add-server. Guidelines: The machinename MUST be 'default_solr_server'; The 'Solr Connector' MUST be 'standard'; 'Solr core' must be set, but the value does not matter; All other values can be updated as you see fit.");
                    $this->confirm("Once the above has been completed, you can run the command `./robo lando-admin:solr-config` to put the Solr configuration in place. Lando must be rebuilt before this can be run.");
                }
                break;

            case 'elasticsearch':
                // Search API gets turned on at the same time as
                // elasticsearch_connector, uninstall it too.
                $add_or_remove_module('elasticsearch_connector', ['search_api']);
                if ($add) {
                    $this->yell('IMPORTANT MANUAL CONFIGURATION:');
                    $this->confirm("The Elasticsearch Connector module has been added and enabled, but you must manually create the core at /admin/config/search/search-api/add-serverr. Guidelines: The machinename MUST be 'default_elasticsearch_server'; The 'ElasticSearch Connector' MUST be 'standard'; 'ElasticSearch URL' must be set, but the value does not matter; All other values can be updated as you see fit.");
                }
                break;

            case 'node':
                if ($add) {
                    $this->confirm('If you need to take action during the build process, please edit the "./orch/build_node.sh" file. By default, this file will install deps with npm and run "gulp" on all custom themes that have a package.json.');
                }
                break;

        }
    }

    /**
     * Sets the best recipe to use.
     *
     * @command lando-admin:set-recipe
     *
     * @return void
     */
    public function landoAdminSetRecipe(): void
    {
        $this->isLandoInit();
        $lando_yml = $this->getLandoYml();
        $lando_dist_yml = $this->getLandoDistYml();
        // https://docs.lando.dev/plugins/drupal/.
        $recipes = ['drupal9', 'drupal10'];
        $drupal_version = explode('.', \Drupal::VERSION)[0];
        $optimal_recipe = "drupal$drupal_version";

        $override_recipe = $lando_yml['recipe'] ?? '';
        // If .lando.dist.yml does not have a default recipe, use the last
        // recipe in the list of all recipes.
        $default_recipe = $lando_dist_yml['recipe'] ?? end($recipes);
        // Only set an override recipe in .lando.yml if the optimal recipe is:
        // * A valid recipe and
        // * Not the same as the default recipe from .lando.dist.yml and
        // * Not already set in .lando.yml.
        if (in_array($optimal_recipe, $recipes) && $default_recipe !== $optimal_recipe && $override_recipe !== $optimal_recipe) {
            $lando_yml['recipe'] = $optimal_recipe;
            $this->saveLandoYml($lando_yml);
            $this->yell("Your new recipe has been set to '$optimal_recipe'");
            return;
        }
        if ($override_recipe) {
            $this->yell("You are currently overriding the default recipe of '$default_recipe' with '$override_recipe");
        }
        else {
            $this->yell("You are using the default recipe of '$default_recipe'");
        }
    }

    /**
     * Set the version of PHP to use.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return void
     *
     * @command lando-admin:set-php
     *
     * @replaceby lando-admin:set-required-shared-services.
     */
    /*public function landoAdminSetPhp(InputInterface $input, OutputInterface $output): void
    {
        $io = new SymfonyStyle($input, $output);
        $lando_yml = $this->getLandoYml();
        $lando_dist_yml = $this->getLandoDistYml();
        $project_override_php_version = $lando_yml['config']['php'] ?? '';
        $default_php_version = $lando_dist_yml['config']['php'];
        $this->yell("The default (recommended) version of PHP is '$default_php_version'");
        if ($project_override_php_version) {
            if (!$this->confirm("Your Lando project is overriding the default version of PHP as '$project_override_php_version'. Would you like change it?")) {
                $this->yell('Cancelled setting PHP version.');
                return;
            }
        } else {
            if (!$this->confirm("You are currently using this default value. Would you like to override it?")) {
                $this->yell('Cancelled setting PHP version.');
                return;
            }
        }
        $chosen_php_version = $io->choice('What version of PHP would you like to use? (https://docs.lando.dev/plugins/php/)', ['8.0', '8.1', '8.2', 'Cancel'], $default_php_version);
        if ($chosen_php_version === 'Cancel') {
            $this->yell('Cancelled setting PHP version.');
            return;
        }
        $lando_yml['config']['php'] = $chosen_php_version;
        $this->saveLandoYml($lando_yml);
    }*/

    /**
     * Copy the Solr config from Drupal to the Solr server config directory.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return void
     *
     * @command lando-admin:solr-config
     */
    public function landoAdminSolrConfig(InputInterface $input, OutputInterface $output): void
    {
        $this->isLandoInit();
        $this->isDrupalInstalled();
        $this->drush(['search-api-solr:get-server-config', 'default_solr_server', 'solr-config.zip']);
        if ($this->taskDeleteDir('solr-conf')
            ->taskExtract('web/solr-config.zip')
            ->to('solr-conf')
            ->taskFilesystemStack()
            ->remove('web/solr-config.zip')
            ->stopOnFail()
            ->run()
            ->wasSuccessful()) {
            $lando_yml = $this->getLandoYml();
            $lando_yml['services']['search']['config']['dir'] = 'solr-conf';
            $this->saveLandoYml($lando_yml);
            $this->rebuildRequired(true, 'the Solr configuration is now in place, you can commit the files in "solr-conf"');
        }
    }

    /**
     * Configure the URLs to be used to access the environment.
     *
     * @return bool
     *   True if the URLs needed to be updated.
     *
     * @command lando:setup-urls
     */
    public function landoSetupUrls(): bool
    {

        $updated = false;
        $this->isLandoInit();
        $lando_file_yml = $this->getLandoYml();
        $lando_local_yml = $this->getLandoLocalYml();
        $lando_dist_yml = $this->getLandoDistYml();
        $use_local_project_name = (bool) $this->getConfig('flags.lando.setupUrlsUseLocalProjectName', 0, true);
        if ($use_local_project_name && !empty($lando_local_yml['name'])) {
            $project_name = $lando_local_yml['name'];
        } else {
            $project_name = $lando_file_yml['name'];
        }
        // This is equivalent to setting drush/drush.yml options.uri.
        // Handy so one does not need to set --uri on drush calls, like uli.
        $drush_option_uri =& $lando_local_yml['services']['appserver']['overrides']['environment']['DRUSH_OPTIONS_URI'];
        if (empty($drush_option_uri) || $drush_option_uri !== $this->getLandoUrl($project_name)) {
            $drush_option_uri = $this->getLandoUrl($project_name);
            $this->saveLandoLocalYml($lando_local_yml);
            $updated = true;
        }
        $proxy_service_exists = [];
        foreach (['mail', 'dbadmin'] as $proxy_service) {
            if (
                isset($lando_local_yml['services'][$proxy_service]) ||
                isset($lando_dist_yml['services'][$proxy_service]) ||
                isset($lando_file_yml['services'][$proxy_service])
            ) {
                $proxy_service_exists[] = $proxy_service;
            }
        }
        $via = 'apache';
        if (!empty($lando_local_yml['config']['via'])) {
            $via = $lando_local_yml['config']['via'];
        } elseif (!empty($lando_file_yml['config']['via'])) {
            $via = $lando_file_yml['config']['via'];
        } elseif (!empty($lando_dist_yml['config']['via'])) {
            $via = $lando_dist_yml['config']['via'];
        }
        // Via may have the version number, like apache:2.4, only get 'apache'.
        [$via] = explode(':', $via);
        if (empty($lando_local_yml['proxy'])) {
            $lando_local_yml['proxy'] = [];
        }
        ksort($lando_local_yml['proxy']);
        $saved_proxy = $lando_local_yml['proxy'];
        unset($lando_local_yml['proxy']);
        // Nginx adds another service (appserver_nginx) to serve the main URL,
        // while apache uses just appserver.
        if ($via === 'nginx') {
            $proxy_service_exists[] = "appserver_$via";
        } else {
            $proxy_service_exists[] = 'appserver';
        }
        foreach ($proxy_service_exists as $proxy_service_exist) {
            $lando_local_yml['proxy'][$proxy_service_exist] = [
                $this->getLandoUrl($project_name, $proxy_service_exist, '')
            ];
        }
        ksort($lando_local_yml['proxy']);
        if ($saved_proxy !== $lando_local_yml['proxy']) {
            $updated = true;
        }
        if ($updated) {
            $this->saveLandoLocalYml($lando_local_yml);
            $this->yell("{$this->lando_local_yml_path} has been written because your URLs have changed");
        }
        return $updated;
    }

    /**
     * Start Lando for the first time.
     *
     * * Ensures lando is installed.
     * * Sets up the URLs.
     * * Starts up Lando.
     * * Installs Drupal.
     * * Set personal services.
     *
     * @command lando:init
     */
    public function landoInit(InputInterface $input, OutputInterface $output): void
    {
        if (!$this->landoReqs($input, $output)) {
            throw new \Exception('Unable to find all requirements. Please re-run this command after installing');
        }
        $this->_exec('vendor/bin/robo lando:setup-urls')->stopOnFail();
        $this->_exec('lando destroy -y')->stopOnFail();
        $this->_exec('lando start')->stopOnFail();
        $this->_exec('lando si')->stopOnFail();
        if ($this->confirm('Your environment has been started, please use the one time login link to login. Would you like to add any personal services (like PhpMyadmin, Mailhog, etc)?')) {
            $this->_exec('./robo lando:set-personal-services');
        }
        // @todo: Ask to enable xdebug
        // @todo: Ask to enable local settings (no cache / twig debug).
    }

    /**
     * Set optional personal (developer) services.
     *
     * @command lando:set-personal-services
     */
    public function landoSetPersonalServices(InputInterface $input, OutputInterface $output): void
    {
        $this->isLandoInit();
        $io = new SymfonyStyle($input, $output);
        $rebuild_required = false;
        $status = $this->setOptionalService($io, 'personal', 'dbadmin', 'dbadmin', 'database admin tool', $service_type, $service_version);
        if (false !== $status) {
            $rebuild_required = true;
        }
        $status = $this->setOptionalService($io, 'personal', 'mail', 'mail', 'email testing server', $service_type, $service_version);
        if (!$rebuild_required && false !== $status) {
            $rebuild_required = true;
        }
        $status = $this->landoSetupUrls();
        if (!$rebuild_required && false !== $status) {
            $rebuild_required = true;
        }
        $this->rebuildRequired($rebuild_required);
    }

    /**
     * Display the requirements to use Lando.
     *
     * @command lando:reqs
     *
     * @return bool
     *   True if all requirements are installed.
     *
     * @throws \Exception
     */
    public function landoReqs(InputInterface $input, OutputInterface $output): bool
    {
        $io = new SymfonyStyle($input, $output);
        $rows = [];
        $missing_software = FALSE;
        if (!$this->addSoftwareTableRow(
            'Lando',
            'lando',
            'https://lando.dev/download/',
            'https://docs.lando.dev/getting-started/requirements.html',
            $rows
        )) {
            $missing_software = TRUE;
        }
        $this->printSoftWareTable($io, $rows, $missing_software);
        return !$missing_software;
    }

    /**
     * Create a duplicate local environment.
     *
     * * Copy all files to a new directory
     * * Adds new URL if required
     * * Cleans up IDE settings for new directory name.
     *
     * @command lando:duplicate-project
     */
    public function landoDuplicateProject(SymfonyStyle $io): void
    {
        $this->isLandoInit();
        $lando_file_yml = $this->getLandoYml();
        $this->say('When creating a duplicate local environment, there are 2 options.');
        $this->say('');
        $this->say('a) Use the same URL as the original project URL (' . $this->getLandoUrl($lando_file_yml['name']) . ')');
        $this->say('b) Use a new URL for the duplicate project (' . $this->getLandoUrl($lando_file_yml['name'] . '-NEW-SUFFIX-VALUE') . ')');
        $this->say('');
        $this->say('"a" is nice because the URL is consistent, but you may get confused which environment you are on. With this option, you should not run them at the same time, you should lando stop on the old and lando start on the new. If you do not, lando will not warn you and it will start using your sites like a load balancer, serving every other request from each.');
        $this->say('"b" is nice because both can run at the same time and you will get a visual distinction on the URL so you know where you are.');
        $this->say('');
        $option_choice = $io->choice('Which option do you prefer?', ['a' => 'a) Same URL, only one at a time', 'b' => 'b) New URL, run at same time', 'cancel' => 'Cancel']);
        if ($option_choice === 'cancel') {
            $this->say('Cancelled');
            return;
        }
        $io->note('Duplicating your project will copy the current root directory to a sibling directory so that they are separate environments.');
        $extra_text = '';
        if ('b' === $option_choice) {
            $extra_text = ' Your new directory will determine the new URL for your new duplicate project.';
        }
        $current_project_dir = basename(getcwd());
        $option_directory_suffix = $io->ask("Which directory do you want to put your project in? Note that it will be prefixed with '$current_project_dir" . '_' . "'.$extra_text", 'code_review');
        // Remove the current directory from the new suffix if they entered it.
        $search = [$current_project_dir . '_', $current_project_dir];
        $option_directory_suffix = str_replace($search, '', $option_directory_suffix);
        if (!strlen($option_directory_suffix)) {
            $this->yell('You have to enter a value that is not your current project directory.');
        }
        $new_project_name = $lando_file_yml['name'] . '_' . $option_directory_suffix;
        $source_dir = realpath(getcwd());
        $target_dir = realpath(getcwd() . DIRECTORY_SEPARATOR . '..') . DIRECTORY_SEPARATOR . $current_project_dir . '_' . $option_directory_suffix;
        if (is_dir($target_dir)) {
            $this->yell("The target directory $target_dir already exists, unable to create duplicate project.");
            return;
        }
        $this->say(sprintf('Your new duplicate project will be copied from %s to %s', $source_dir, $target_dir));
        if ('b') {
            $this->say('Your new duplicate project URL will be ' . $this->getLandoUrl($new_project_name));
        }
        if (!$this->confirm('Are you sure you want to continue?')) {
            $this->say('Cancelled');
            return;
        }
        $this->say('This can take a while, please don\'t close this process...');
        // $this->taskCopyDir() will make copies of symlink source, so use `cp`
        // instead.
        `cp -a $source_dir $target_dir`;
        if (!is_dir($target_dir)) {
            $this->yell('There was an error copying the folder structure.');
            return;
        }
        $this->say('Your local environment has been created');

        // Move into the new environment and do some cleanup on PhpStorm files.
        // And also be able to work on the new environment's .lando.local.yml.
        chdir($target_dir);
        // Remove the workspace which contains unimportant files.
        if (file_exists('.idea/workspace.xml')) {
            $this->taskFilesystemStack()
                ->remove('.idea/workspace.xml')
                ->run();
        }
        $source_dir_name = basename($source_dir);
        $target_dir_name = basename($target_dir);
        // The .iml file needs to be renamed to the new dir if it exists.
        if (file_exists(".idea/$source_dir_name.iml")) {
            $this->taskFilesystemStack()
                ->rename(".idea/$source_dir_name.iml", ".idea/$target_dir_name.iml")
                ->run();
            // Now that the iml file has changed names, update its reference in
            // modules.xml.
            if (file_exists('.idea/modules.xml')) {
                $this->taskReplaceInFile('.idea/modules.xml')
                    ->from(".idea/$source_dir_name.iml")
                    ->to(".idea/$target_dir_name.iml")
                    ->run();
            }
        }

        // Every lando project needs a unique name, otherwise they will use
        // the same containers. Override name in .lando.yml with a new one
        // in .lando.local.yml.
        $lando_local_yml = $this->getLandoLocalYml();
        $lando_local_yml['name'] = $new_project_name;
        $this->saveLandoLocalYml($lando_local_yml);

        if ('b' === $option_choice) {
            // Set this flag so that when landoSetupUrls is run, the correct
            // choice is made.
            $this->saveConfig('flags.lando.setupUrlsUseLocalProjectName', 1, true);
            $this->landoSetupUrls();
            $this->say('New URLs have been set for all your services that had them.');
        } else {
            $this->say('Please stop the current environment before starting your new one.');
        }

        $this->say("Finished creating your new environment. Please change to the directory ../$target_dir_name first. It is ready to be started.");
    }

    /**
     * An array of services by 'type' that are available to install.
     *
     * For example, 'php' is the only option for PHP while 'web' can be 'apache'
     * or 'nginx'.
     *
     * @return array[]
     */
    public static function sharedServices(): array
    {
        return [
            'php' => [
                'php' => [
                    'doc' => 'https://docs.lando.dev/plugins/php/',
                    'versions' => [
                        '8.0',
                        '8.1',
                        '8.2',
                        '8.3',
                    ]
                ],
            ],
            'web' => [
                'apache' => [
                    'doc' => 'https://docs.lando.dev/plugins/apache/',
                    'versions' => [
                        '2.4',
                    ],
                ],
                'nginx' => [
                    'doc' => 'https://docs.lando.dev/plugins/nginx/',
                    'versions' => [
                        '1.25',
                        '1.24',
                        '1.23',
                        '1.22',
                        '1.21',
                        '1.20',
                        '1.19',
                        '1.18',
                        '1.17',
                        '1.16',
                    ],
                ]
            ],
            'database' => [
                'mariadb' => [
                    'doc' => 'https://docs.lando.dev/plugins/mariadb/',
                    'versions' => [
                        '11.3',
                        '11.2',
                        '11.1',
                        '11.0',
                        '10.6',
                        '10.5',
                        '10.4',
                        '10.3',
                    ]
                ],
                'mysql' => [
                    'doc' => 'https://docs.lando.dev/plugins/mysql/',
                    'versions' => [
                        '8.0',
                        '5.7',
                    ],
                ],
                'postgres' => [
                    'doc' => 'https://docs.lando.dev/plugins/postgres/',
                    'versions' => [
                        '15',
                        '14',
                        '13',
                        '12',
                        '11',
                        '11.1.0',
                        '10',
                        '10.6.0',
                        '9.6',
                    ]

                ],
            ],
            'cache' => [
                'memcached' => [
                    'doc' => 'https://docs.lando.dev/plugins/memcached/',
                    'versions' => [
                        '1',
                        '1.5.12',
                        '1.5.x',
                    ],
                ],
                'redis' => [
                    'doc' => 'https://docs.lando.dev/plugins/redis/',
                    'versions' => [
                        '7',
                        '7.0',
                        '6',
                        '6.0',
                        '5',
                        '5.0',
                        '4',
                        '4.0',
                        '2.8',
                    ]
                ],
            ],
            'search' => [
                'solr' => [
                    'doc' => 'https://docs.lando.dev/plugins/solr/#supported-versions',
                    'versions' => [
                        '9',
                        '9.0',
                        '8',
                        '8.11',
                        '8.10',
                        '8.9',
                        '8.8',
                        '8.7',
                        '8.6.',
                        '8.5',
                        '8.4',
                        '8.3',
                        '8.2',
                        '8.1',
                        '8.0',
                        '7',
                        '7.7',
                        '7.6',
                    ],
                ],
                'elasticsearch' => [
                    'doc' => 'https://docs.lando.dev/plugins/elasticsearch/#supported-versions / https://hub.docker.com/r/bitnami/elasticsearch/tags',
                    // The ones on lando.dev like .x are not correct, you have
                    // to use the patched version. Look for more if needed on
                    // docker hub.
                    // Versions <7 do not work with elasticsearch_connector
                    // because the module makes sure 'ElasticSearch' is in the
                    // headers from a ping to the search server, and they are
                    // not.
                    'versions' => [
                        '8',
                        '8.2.3',
                    ],
                ],
            ],
            'dbadmin' => [
                'phpmyadmin' => [
                    'doc' => 'https://docs.lando.dev/plugins/mailhog/',
                    'versions' => ['default'],
                ],
            ],
            'mail' => [
                'mailhog' => [
                    'doc' => 'https://docs.lando.dev/plugins/mailhog/',
                    'versions' => ['default'],
                    'additional_config' => [
                        'hogfrom' => ['appserver']
                    ],
                ],
            ],
            'node' => [
                'node' => [
                    'doc' => 'https://docs.lando.dev/plugins/node/#supported-versions',
                    'versions' => [
                        '20',
                        '19',
                        '18',
                        '17',
                        '16',
                        '15',
                        '14',
                    ],
                    'additional_config' => [
                        'build' => ['./orch/build_node.sh'],
                        'scanner' => false,
                    ],
                ],
            ],
        ];

    }

}
