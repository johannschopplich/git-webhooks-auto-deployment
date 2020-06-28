<?php
/**
 * Git Webhooks Auto Deployment
 *
 * Works with webhooks of common git services, including GitHub, GitLab and Gitea.
 * Also runnable via direct URL.
 * Based on: https://github.com/katzueno/git-Webhooks-Auto-Deploy-PHP-Script
 *
 * @author Johann Schopplich <pkg@johannschopplich.com>
 * @version 1.1.0
 */

/**
 * The timezone format used for logging.
 * @var  Timezone
 * @link http://php.net/manual/en/timezones.php
 */
date_default_timezone_set('Europe/Berlin');

/**
 * The Options.
 * Only `secret` and `directory` are required.
 * @var array
 */
$options = [
    'secret'        => 'WEBHOOKSECRET', // @link https://example.com/deployments.php?secret=WEBHOOKSECRET
    'directory'     => '/var/www/example.com',
    'work_dir'      => false,
    'log'           => 'deploy.log',
    'branch'        => 'master',
    'remote'        => 'origin',
    'date_format'   => 'Y-m-d H:i:sP',
    'syncSubmodule' => false,
    'reset'         => false,
    'git_bin_path'  => '/usr/bin/git',
];

/**
 * Create a new `Deploy` class, execute git events and any defined post-deploy instructions.
 */
$deploy = new Deploy($options);
// $deploy->post_deploy = function () use ($options, $deploy) {
//     exec('rm -rf ' . $options['directory'] . '/storage/cache/example.com');
//     $deploy->log('Flushing Kirby cache…');
// };
$deploy->validateSignature();
$deploy->execute();

/**
 * The core deploy class.
 */
class Deploy {

    /**
     * A callback function to call after the deploy has finished.
     *
     * @var callback
     */
    public $post_deploy;

    /**
     * The name of the file that will be used for logging deployments. Set to
     * FALSE to disable logging.
     *
     * @var string
     */
    private $_log = 'deploy.log';

    /**
     * The timestamp format used for logging.
     *
     * @link http://www.php.net/manual/en/function.date.php
     * @var  string
     */
    private $_date_format = 'Y-m-d H:i:sP';

    /**
     * The path to git
     *
     * @var string
     */
    private $_git_bin_path = 'git';

    /**
     * The secret key (or token) for securing the script.
     *
     * @var string
     */
    private $_secret;

    /**
     * The directory where your git repository is located, can be
     * a relative or absolute path from this PHP script on server.
     *
     * @var string
     */
    private $_directory;

    /**
     * The directory where your git work directory is located, can be
     * a relative or absolute path from this PHP script on server.
     *
     * @var string
     */
    private $_work_dir;

    /**
     * Determine if it will execute to git checkout to work directory,
     * or git pull.
     *
     * @var boolean
     */
    private $_topull = false;

    /**
     * The branch to work with.
     *
     * @var string
     */
    private $_branch = 'master';

    /**
     * The orgin to use.
     *
     * @var string
     */
    private $_remote = 'origin';

    /**
     * Sets up defaults.
     *
     * @param array $option Information about the deployment
     */
    public function __construct($options = [])
    {
        $available_options = ['secret', 'directory', 'work_dir', 'log', 'date_format', 'branch', 'remote', 'syncSubmodule', 'reset', 'git_bin_path'];

        foreach ($options as $option => $value) {
            if (in_array($option, $available_options)) {
                $this->{'_' . $option} = $value;
                if (($option === 'directory') || ($option === 'work_dir' && $value)) {
                    // Determine the directory path
                    $this->{'_' . $option} = realpath($value) . '/';
                }
            }
        }

        $this->_topull = false;
        if (empty($this->_work_dir) || ($this->_work_dir === $this->_directory)) {
            $this->_work_dir = $this->_directory;
            $this->_directory = $this->_directory . '.git';
            $this->_topull = true;
        }

        $this->log('Attempting deployment…');
        $this->log('Git Directory: ' . $this->_directory);
        $this->log('Work Directory: ' . $this->_work_dir);

        // Set a default header for every response
        header('Content-type: application/json');
    }

    /**
     * Writes a message to the log file.
     *
     * @param string $message The message to write
     * @param string $type    The type of log message (e.g. INFO, DEBUG, ERROR, etc.)
     */
    public function log($message, $type = 'INFO')
    {
        if ($this->_log) {
            // Set the name of the log file
            $filename = $this->_log;

            if (!file_exists($filename)) {
                // Create the log file
                file_put_contents($filename, '');

                // Allow anyone to write to log files
                chmod($filename, 0666);
            }

            // Write the message into the log file
            // Format: time --- type: message
            file_put_contents($filename, date($this->_date_format) . ' --- ' . $type . ': ' . $message . PHP_EOL, FILE_APPEND);
        }
    }

    /**
     * Parse delivered hashed or plain signature and bail if verification fails.
     */
    public function validateSignature()
    {
        try {
            // GitHub and Gitea forward a hashed secret
            $hashed_signature = $_SERVER['HTTP_X_HUB_SIGNATURE'] ?? $_SERVER['HTTP_X_GITEA_SIGNATURE'] ?? null;
            // GitLab just sends the plain token
            $generic_signature = $_SERVER['HTTP_X_GITLAB_TOKEN'] ?? $_GET['secret'] ?? null;

            if (empty($hashed_signature) && empty($generic_signature)) {
                throw new Exception('No secret given.');
            }

            // Check content type of POST requests
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_SERVER['CONTENT_TYPE'] !== 'application/json') {
                throw new Exception('Content type doesn\' match `application/json`.');
            }

            if (!empty($hashed_signature)) {
                // GitHub prepends the applied hashing algorithm (`sha1`) in its signature
                // Gitea doesn't hint its hashing algorithm, but implies `sha256`
                if (isset($_SERVER['HTTP_X_HUB_SIGNATURE'])) {
                    // Split signature into algorithm and hash
                    list($algo, $hash) = explode('=', $hashed_signature, 2);
                } elseif (isset($_SERVER['HTTP_X_GITEA_SIGNATURE'])) {
                    $algo = 'sha256';
                    $hash = $hashed_signature;
                }

                // Get payload
                $payload = file_get_contents('php://input');

                // Calculate hash based on payload and the secret
                $payload_hash = hash_hmac($algo, $payload, $this->_secret);

                // Check if hashes are equivalent
                if (!hash_equals($hash, $payload_hash)) {
                    throw new Exception('Hook secret doesn\'t match.');
                }
            } elseif (!empty($generic_signature) && $generic_signature !== $this->_secret) {
                throw new Exception('Hook secret doesn\'t match.');
            }
        } catch (Exception $error) {
            $this->log($error, 'ERROR');
            http_response_code(401);
            exit(json_encode(['error' => $error->getMessage()], JSON_PRETTY_PRINT));
        }
    }

    /**
     * Executes the necessary commands to deploy the website.
     */
    public function execute()
    {
        try {
            // Git Submodule - Measure the execution time
            $strtedAt = microtime(true);

            // Discard any changes to tracked files since our last deploy
            if ($this->_reset) {
                exec($this->_git_bin_path . ' --git-dir=' . $this->_directory . ' --work-tree=' . $this->_work_dir . ' reset --hard HEAD 2>&1', $output);
                if (is_array($output)) {
                    $output = implode(' ', $output);
                }
                $this->log('Reseting repository… ' . $output);
            }

            // Update the local repository
            exec($this->_git_bin_path . ' --git-dir=' . $this->_directory . ' --work-tree=' . $this->_work_dir . ' fetch ' . $this->_remote . ' ' . $this->_branch, $output, $return_var);
            if ($return_var === 0) {
                $this->log('Fetching changes… ' . implode(' ', $output));
            } else {
                throw new Exception(implode(' ', $output));
            }

            // Checking out to web directory
            if ($this->_topull) {
                exec('cd ' . $this->_directory . ' && GIT_WORK_TREE=' . $this->_work_dir . ' ' . $this->_git_bin_path . ' pull ' . $this->_remote . ' ' . $this->_branch . ' 2>&1', $output, $return_var);
                if ($return_var === 0) {
                    $this->log('Pulling changes to directory… ' . implode(' ', $output));
                } else {
                    throw new Exception(implode(' ', $output));
                }
            } else {
                exec('cd ' . $this->_directory . ' && GIT_WORK_TREE=' . $this->_work_dir . ' ' . $this->_git_bin_path . ' checkout -f', $output, $return_var);
                if ($return_var === 0) {
                    $this->log('Checking out changes to www directory… ' . implode(' ', $output));
                } else {
                    throw new Exception(implode(' ', $output));
                }
            }

            if ($this->_syncSubmodule) {
                // Wait 2 seconds if main git pull takes less than 2 seconds
                $endedAt = microtime(true);
                $mDuration = $endedAt - $strtedAt;
                if ($mDuration < 2) {
                    $this->log('Waiting for 2 seconds to execute git submodule update.');
                    sleep(2);
                }

                // Update the submodules
                $output = '';
                exec($this->_git_bin_path . ' --git-dir=' . $this->_directory . ' --work-tree=' . $this->_work_dir . ' submodule update --init --recursive --remote', $output);
                if (is_array($output)) {
                    $output = implode(' ', $output);
                }

                $this->log('Updating submodules…' . $output);
            }

            if (is_callable($this->post_deploy)) {
                call_user_func($this->post_deploy, $this->_data);
            }

            $this->log('Deployment successful.');
            echo json_encode(['status' => 'ok', JSON_PRETTY_PRINT]);
        } catch (Exception $error) {
            $this->log($error, 'ERROR');
            http_response_code(500);
            exit(json_encode(['error' => 'Exception in deploy script line ' . $error->getLine()], JSON_PRETTY_PRINT));
        }
    }
}
