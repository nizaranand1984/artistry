<?php
// @codingStandardsIgnoreStart
/*
UpdraftPlus Addon: backblaze:Backblaze Support
Description: Backblaze Support
Version: 1.2
Shop: /shop/backblaze/
Include: includes/backblaze
IncludePHP: methods/addon-base-v2.php
RequiresPHP: 5.3.3
Latest Change: 1.14.4
*/
// @codingStandardsIgnoreEnd

if (!defined('UPDRAFTPLUS_DIR')) die('No direct access allowed');

if (!class_exists('UpdraftPlus_RemoteStorage_Addons_Base_v2')) require_once(UPDRAFTPLUS_DIR.'/methods/addon-base-v2.php');
/**
 * Possible enhancements:
 * - Investigate porting to WP HTTP API so that curl is not required
 */
class UpdraftPlus_Addons_RemoteStorage_backblaze extends UpdraftPlus_RemoteStorage_Addons_Base_v2 {

	private $_large_file_id;
	
	private $_sha1_of_parts;
	
	private $_uploaded_size;
	
	private $chunk_size = 5242880;

	/**
	 * Constructor
	 */
	public function __construct() {
		// 3rd parameter: chunking? 4th: Test button?
		parent::__construct('backblaze', 'Backblaze B2', true, true);
		// Set it any lower, any you will get an error when calling /b2_finish_large_file upon finishing: 400, Message: Part number 1 is smaller than 5000000 bytes"
		if (defined('UPDRAFTPLUS_UPLOAD_CHUNKSIZE') && UPDRAFTPLUS_UPLOAD_CHUNKSIZE > 0) $this->chunk_size = max(UPDRAFTPLUS_UPLOAD_CHUNKSIZE, 5000000);
	}
	
	/**
	 * Upload a single file
	 *
	 * @param String $file		 - the basename of the file to upload
	 * @param String $local_path - the full path of the file
	 *
	 * @return Boolean - success status. Failures can also be thrown as exceptions.
	 */
	public function do_upload($file, $local_path) {
	
		global $updraftplus;

		$opts = $this->options;
		$storage = $this->get_storage();

		if (is_wp_error($storage)) throw new Exception($storage->get_error_message());
		if (!is_object($storage)) throw new Exception("Backblaze service error (got a ".gettype($storage).")");
		
		$backup_path = empty($opts['backup_path']) ? '' : trailingslashit($opts['backup_path']);
		$remote_path = $backup_path.$file;
		
		$file_hash = md5($file);
		$this->_uploaded_size = $this->jobdata_get('total_bytes_sent_'.$file_hash, 0);
		
		if (!file_exists($local_path) || !is_readable($local_path)) throw new Exception("Could not read file: $local_path");
		
		$bucket_name = $opts['bucket_name'];
		// Create bucket if bucket doesn't exists
		if (!isset($this->is_upload_bucket_exist) && $this->is_valid_bucket_name($bucket_name)) {
			$buckets = $this->get_bucket_names_array();
			if (!in_array($bucket_name, $buckets)) {
				$new_bucket_created = $storage->createPrivateBucket($bucket_name);
				if ($new_bucket_created) {
					$this->is_upload_bucket_exist = true;
					$updraftplus->log("Backblaze: bucket was not found, but a new private bucket has now been created: ".$bucket_name);
				} else {
					$updraftplus->log("Backblaze: bucket was not found, and creation of a new private bucket failed: ".$bucket_name);
				}
			} else {
				$this->is_upload_bucket_exist = true;
			}
		}
		
		if (1 === ($ret = $updraftplus->chunked_upload($this, $file, "backblaze://".trailingslashit($bucket_name).$backup_path.$file, 'Backblaze', $this->chunk_size, $this->_uploaded_size))) {
		
			$result = $storage->upload(array(
				'BucketName' => $opts['bucket_name'],
				'FileName'   => $remote_path,
				'Body'	   => file_get_contents($local_path),
			));
			
			if (is_object($result) && is_callable(array($result, 'getSize')) && $result->getSize() > 1) {
				$ret = true;
			} else {
				$ret = false;
				$updraftplus->log("Backblaze: all-in-one upload fail: ".serialize($result));
			}
			
		}
		
		return $ret;

	}

	/**
	 * N.B. If we ever use varying-size chunks, we must be careful as to what we do with $chunk_index
	 *
	 * @param String   $file 			Full path for the file being uploaded
	 * @param Resource $fp 				File handle to read upload data from
	 * @param Integer  $chunk_index 	Index of chunked upload
	 * @param Integer  $upload_size 	Size of the upload, in bytes
	 * @param Integer  $upload_start	How many bytes into the file the upload process has got
	 * @param Integer  $upload_end		How many bytes into the file we will be after this chunk is uploaded
	 * @param Integer  $total_file_size Total file size
	 *
	 * @return Boolean|WP_Error
	 */
	public function chunked_upload($file, $fp, $chunk_index, $upload_size, $upload_start, $upload_end, $total_file_size) {
	
		// Already done?
		if ($upload_start < $this->_uploaded_size) return 1;

		global $updraftplus;
	
		$file_hash = md5($file);

		$upload_state = $this->jobdata_get('upload_state_'.$file_hash, array());
		// An upload URL is valid for 24 hours. But, we'll only use them for 1 hour, in case something else happens to invalidate it (we don't want to wait a whole day before getting a new one).
		if (!empty($upload_state['saved_at']) && $upload_state['saved_at'] < time() - 3600) $upload_state = array();
		
		$large_file_id = empty($upload_state['large_file_id']) ? false : $upload_state['large_file_id'];
		$upload_url = empty($upload_state['upload_url']) ? false : $upload_state['upload_url'];
		$auth_token = empty($upload_state['auth_token']) ? false : $upload_state['auth_token'];
		$need_new_state = ($large_file_id && $upload_url && $auth_token) ? false : true;
		
		$storage = $this->get_storage();

		if (is_wp_error($storage)) return $storage;
		if (!is_object($storage)) return new WP_Error('no_backblaze_service', "Backblaze service error (got a ".gettype($storage).")");

		$opts = $this->options;
		$backup_path = empty($opts['backup_path']) ? '' : trailingslashit($opts['backup_path']);
		$remote_path = $backup_path.$file;

		if (!$large_file_id) {
			$updraftplus->log("Backblaze: initiating multi-part upload");
			try {
				$response = $storage->uploadLargeStart(array(
					'FileName'   => $remote_path,
					'BucketName' => $opts['bucket_name'],
				));

				if (empty($response['fileId'])) {
					$updraftplus->log('Backblaze: Unexpected response to uploadLargeStart: '.serialize($response));
					return false;
				}

			} catch (Exception $e) {
				$updraftplus->log('Backblaze: Unexpected chunk uploading exception ('.get_class($e).'): '.$e->getMessage().' (line: '.$e->getLine().', file: '.$e->getFile().')');
				return false;
			}

			$large_file_id = $response['fileId'];

		}

		$this->_large_file_id = $large_file_id;
		
		// $large_file_id is now set
		
		if (!$upload_url || !$auth_token) {
			try {
				$updraftplus->log("Backblaze: requesting multi-part file upload url (id $large_file_id)");
				$response = $storage->uploadLargeUrl(array(
					'FileId' => $large_file_id,
				));
				if (empty($response['authorizationToken']) || empty($response['uploadUrl'])) {
					$updraftplus->log('Unexpected response to uploadLargeUrl: '.serialize($response));
					return false;
				}

			} catch (Exception $e) {
				$updraftplus->log('Backblaze: Unexpected error when getting upload URL ('.get_class($e).'): '.$e->getMessage().' (line: '.$e->getLine().', file: '.$e->getFile().')');
				return false;
			}
			$auth_token = $response['authorizationToken'];
			$upload_url = $response['uploadUrl'];
		}
		
		if ($need_new_state) {
			$this->jobdata_set('upload_state_'.$file_hash, array(
				'large_file_id' => $large_file_id,
				'upload_url' => $upload_url,
				'auth_token' => $auth_token,
				// N.B. An upload URL is valid for 24 hours
				'saved_at' => time()
			));
		}
		
		$timer_start = microtime(true);
		$sha1_of_parts = $this->jobdata_get('sha1_of_parts_'.$file_hash, array());

		if (false === ($data = fread($fp, $upload_size))) {
			$updraftplus->log(__('Error: unexpected file read fail', 'updraftplus'), 'error');
			$updraftplus->log("File read fail (fread() returned false)");
			return false;
		}
		
		$hash = sha1($data);
		array_push($sha1_of_parts, $hash);

		try {
			$response = $storage->uploadLargePart(array(
				'AuthorizationToken' => $auth_token,
				'FilePartNo' => $chunk_index,
				'UploadUrl' => $upload_url,
				'Body' => $data,
			));
			if (!is_array($response) || !isset($response['partNumber'])) {
				$updraftplus->log("Unexpected response to uploadLargePart: ".serialize($response));
				return false;
			}
		} catch (Exception $e) {
			return new WP_Error('backblaze_chunk_upload_error', __('Error:', 'updraftplus')." {$e->getCode()}, Message: {$e->getMessage()}");
		}
		
		$this->_sha1_of_parts = $sha1_of_parts;

		$this->jobdata_set('total_bytes_sent_'.$file_hash, $upload_end + 1);
		$this->jobdata_set('sha1_of_parts_'.$file_hash, $sha1_of_parts);
		
		return true;
	}

	/**
	 * Called when all chunks have been uploaded, to allow any required finishing actions to be carried out
	 *
	 * @param String $file - the basename of the file being uploaded
	 *
	 * @return Integer|Boolean - success or failure state of any finishing actions
	 */
	public function chunked_upload_finish($file) {

		$file_hash = md5($file);
		
		$storage = $this->get_storage();
		
		// This happens if chunked_upload_finish is called without chunked_upload having been called
		if (empty($this->_large_file_id)) {
		
			$upload_state = $this->jobdata_get('upload_state_'.$file_hash, array());
			
			// An upload URL is valid for 24 hours. But, we'll only use them for 1 hour, in case something else happens to invalidate it (we don't want to wait a whole day before getting a new one).
			if (!empty($upload_state['saved_at']) && $upload_state['saved_at'] < time() - 3600) $upload_state = array();
			
			$this->_large_file_id = empty($upload_state['large_file_id']) ? false : $upload_state['large_file_id'];
			
			$this->_sha1_of_parts = $this->jobdata_get('sha1_of_parts_'.$file_hash, array());
			
		}

		try {
			$response = $storage->uploadLargeFinish(array(
				'FileId' => $this->_large_file_id,
				'FilePartSha1Array' => $this->_sha1_of_parts,
			));
		} catch (Exception $e) {
			global $updraftplus;
			
			if (preg_match('/No active upload for: .*/', $e->getMessage())) {
				$updraftplus->log("Backblaze: upload: b2_finish_large_file has already been called ('".$e->getMessage()."')");
				return 1;
			} else {
				$updraftplus->log("Exception in uploadLargeFinish(): {$e->getCode()}, Message: {$e->getMessage()} (line: {$e->getLine()}, file: {$e->getFile()})");
			}
			return false;
		}
		
		global $updraftplus;
		$updraftplus->log('Backblaze: upload: success (b2_finish_large_file called successfully; chunks='.count($this->_sha1_of_parts).', file ID returned='.$response->getId().', size='.$response->getSize().')');

		// Clean-up
		$this->jobdata_delete('upload_state_'.$file_hash);
		$this->jobdata_delete('sha1_of_parts_'.$file_hash);

		// (int)1 means 'we already logged', as opposed to (boolean)true which does not
		return 1;
	}

	/**
	 * Perform a download of the requested file
	 *
	 * @param String  $file	  		- the file (basename) to download
	 * @param String  $fullpath		- the full path to download it too
	 * @param Integer $start_offset - byte marker to begin at (starting from 0)
	 *
	 * @return Boolean|Integer - success/failure, or a byte counter of how much has been downloaded. Exceptions can also be thrown for errors.
	 */
	public function do_download($file, $fullpath, $start_offset) {
		global $updraftplus;

		$remote_files = $this->do_listfiles($file);

		if (is_wp_error($remote_files)) {
			throw new Exception('Download error ('.$remote_files->get_error_code().'): '.$remote_files->get_error_message());
		}

		foreach ($remote_files as $file_info) {
			if ($file_info['name'] == $file) {
				return $updraftplus->chunked_download($file, $this, $file_info['size'], true, null, 2*1048576);
			}
		}
		
		$updraftplus->log("Backblaze: $file: file not found in listing of remote directory");
		
		return false;

	}
	
	/**
	 * Callback used by by chunked downloading API
	 *
	 * @param String $file	  - the file (basename) to be downloaded
	 * @param Array	 $headers - supplied headers
	 * @param Mixed	 $data	  - pass-back from our call to the API (which we don't use)
	 *
	 * @return String - the data downloaded
	 */
	public function chunked_download($file, $headers, $data) {
	
		$storage = $data;
	
		global $updraftplus;
		$storage = $data[0];
		$file_obj = $data[1];

		// $curl_options = array();
		// if (is_array($headers) && !empty($headers['Range']) && preg_match('/bytes=(.*)$/', $headers['Range'], $matches)) {
		// $curl_options[CURLOPT_RANGE] = $matches[1];

		$opts = $this->options;
		$storage = $this->get_storage();

		$backup_path = empty($opts['backup_path']) ? '' : trailingslashit($opts['backup_path']);
		
		$options = array(
			'BucketName' => $opts['bucket_name'],
			'FileName'   => $backup_path.$file,
		);
		
		if (!empty($headers)) $options['headers'] = $headers;
		
		$remote_file = $storage->download($options);

		return is_string($remote_file) ? $remote_file : false;

	}

	/**
	 * Acts as a WordPress options filter
	 *
	 * @param Array $settings - pre-filtered settings
	 *
	 * @return Array filtered settings
	 */
	public function options_filter($settings) {
		if (is_array($settings) && !empty($settings['version']) && !empty($settings['settings'])) {
			foreach ($settings['settings'] as $instance_id => $instance_settings) {
				if (!empty($instance_settings['backup_path'])) {
					$settings['settings'][$instance_id]['backup_path'] = trim($instance_settings['backup_path'], "/ \t\n\r\0\x0B");
				}
			}
		}
		return $settings;
	}
	
	/**
	 * Delete an indicated file from remote storage
	 *
	 * @param String $file - the file (basename) to delete
	 *
	 * @return Boolean - success/failure status of the delete operation. Throwing exception is also permitted.
	 */
	public function do_delete($file) {
	
		$opts = $this->options;

		$storage = $this->get_storage();

		$backup_path = empty($opts['backup_path']) ? '' : trailingslashit($opts['backup_path']);
		
		$result = $storage->deleteFile(array(
			'FileName'   => $backup_path.$file,
			'BucketName' => $opts['bucket_name'],
		));

		return $result;
		
	}

	/**
	 * This method is used to get a list of backup files for the remote storage option
	 *
	 * @param  String $match - a string to match when looking for files
	 *
	 * @return Array|WP_Error - returns an array of files (arrays with keys 'name' (basename) and (optional) 'size' (in bytes)) or a WordPress error. Throwing an exception is also allowed.
	 */
	public function do_listfiles($match = 'backup_') {

		global $updraftplus;
		$opts = $this->get_options();
		$storage = $this->get_storage();
		
		// When listing, paths in the root must not begin with a slash
		$backup_path = empty($opts['backup_path']) ? '' : trailingslashit($opts['backup_path']);

		try {
			$remote_files = $storage->listFiles(array(
				'BucketName' => $opts['bucket_name'],
				'Prefix' => $backup_path.$match
			));
		} catch (Exception $e) {
			return new WP_Error('backblaze_list_error', $e->getMessage().' (line: '.$e->getLine().', file: '.$e->getFile().')');
		}

		if (is_wp_error($remote_files)) return $remote_files;
		
		$files = array();

		foreach ($remote_files as $k => $file) {
			$file_name = $file->getName();
			if ($backup_path && 0 !== strpos($file_name, $backup_path)) continue;
			$files[] = array(
				'name' => substr($file_name, strlen($backup_path)),
				'size' => $file->getSize(),
				// 'fid'  => $file->getId(),
			);
		}

		return $files;
		
	}

	/**
	 * Get a list of parameters required to be present for a credential tests, plus descriptions
	 *
	 * @return Array
	 */
	public function get_credentials_test_required_parameters() {
		return array(
			'account_id' => __('Account ID', 'updraftplus'),
			'key' => __('Account Key', 'updraftplus')
		);
	}
	
	/**
	 * Run a credentials test. Output can be echoed.
	 *
	 * @param String $testfile		  - basename to use for the test
	 * @param Array  $posted_settings - settings to use
	 *
	 * @return Boolean - success/failure status
	 */
	protected function do_credentials_test($testfile, $posted_settings = array()) {

		$bucket_name = $posted_settings['bucket_name'];
		
		$result = false;
		$storage = $this->get_storage();
		
		try {
			if ($this->is_valid_bucket_name($bucket_name)) {
				$buckets = $this->get_bucket_names_array();
				$new_bucket_created = false;
				if (!in_array($bucket_name, $buckets)) {
					 $new_bucket_created = $storage->createPrivateBucket($bucket_name);
				}
				
				if (in_array($bucket_name, $buckets) || $new_bucket_created) {
					$backup_path = empty($posted_settings['backup_path']) ? '' : trailingslashit($posted_settings['backup_path']);
	
					// Now try to write
					$result = $storage->upload(array(
						'BucketName' => $bucket_name,
						'FileName'   => $backup_path.$testfile,
						'Body'	   => 'This is a test file resulting from pressing the "Test" button in UpdraftPlus, https://updraftplus.com. If it is still here afterwards, then something went wrong deleting it - you should delete it manually.',
					));
					
					if (is_object($result) && is_callable(array($result, 'getSize')) && $result->getSize() > 1) {
						$result = true;
					}
				} elseif (!$new_bucket_created) {
					printf(__("Failure: We could not successfully access or create such a bucket. Please check your access credentials, and if those are correct then try another bucket name (as another %s user may already have taken your name).", 'updraftplus'), 'Backblaze');
				}
			} else {
				echo __('Invalid bucket name', 'updraftplus')."\n";
			}
		} catch (Exception $e) {
			echo get_class($e).': '.$e->getMessage()."\n";
		}

		return $result;
		
	}
	
	/**
	 * Delete a temporary file use for a credentials test. Output can be echo-ed.
	 *
	 * @param String $testfile		  - the basename of the file to delete
	 * @param Array  $posted_settings - the settings to use
	 *
	 * @return void
	 */
	protected function do_credentials_test_deletefile($testfile, $posted_settings) {
		
		try {
			$backup_path = empty($posted_settings['backup_path']) ? '' : trailingslashit($posted_settings['backup_path']);
			$storage = $this->get_storage();
		
			$result = $storage->deleteFile(array(
				'FileName'   => $backup_path.$testfile,
				'BucketName' => $posted_settings['bucket_name'],
			));

		} catch (Exception $e) {
			echo __('Delete failed:', 'updraftplus').' '.$e->getMessage().' ('.$e->getCode().', '.get_class($e).') (line: '.$e->getLine().', file: '.$e->getFile().')';
		}
	}

	/**
	 * Retrieve a list of supported features for this storage method
	 * This method should be over-ridden by methods supporting new
	 * features.
	 *
	 * @see UpdraftPlus_BackupModule::get_supported_features()
	 *
	 * @return Array - an array of supported features (any features not
	 * mentioned are assumed to not be supported)
	 */
	public function get_supported_features() {
		// This options format is handled via only accessing options via $this->get_options()
		return array('multi_options', 'config_templates', 'multi_storage');
	}
	
	/**
	 * Retrieve default options for this remote storage module.
	 *
	 * @return Array - an array of options
	 */
	public function get_default_options() {
		return array(
			'account_id' => '',
			'key' => '',
			'bucket_name' => '',
			'backup_path' => '',
		);
	}
	
	/**
	 * Perform any boot-strapping functions, and return a client instance
	 *
	 * @param Array	  $opts	   - instance options
	 * @param Boolean $connect - whether to also set up a connection (if supported by this method)
	 *
	 * @return UpdraftPlus_Backblaze_CurlClient|WP_Error - the storage object. It should also be stored as $this->storage.
	 */
	public function do_bootstrap($opts, $connect = true) {
		$storage = $this->get_storage();

		if (!empty($storage) && !is_wp_error($storage)) return $storage;
		
		try {

			if (!is_array($opts)) $opts = $this->get_options();
	
			if (!class_exists('UpdraftPlus_Backblaze_CurlClient')) include_once UPDRAFTPLUS_DIR.'/includes/Backblaze/CurlClient.php';

			if (empty($opts['account_id']) || empty($opts['key'])) return new WP_Error('no_settings', __('No settings were found', 'updraftplus').' (Backblaze)');
			
			$storage = new UpdraftPlus_Backblaze_CurlClient($opts['account_id'], $opts['key']);

			$this->set_storage($storage);
			
		} catch (Exception $e) {
			return new WP_Error('blob_service_failed', 'Error when attempting to setup Backblaze access (please check your credentials): '.$e->getMessage().' ('.$e->getCode().', '.get_class($e).') (line: '.$e->getLine().', file: '.$e->getFile().')');
		}

		return $storage;

	}
	
	/**
	 * Check whether options have been set up by the user, or not
	 *
	 * @param Array $opts - the potential options
	 *
	 * @return Boolean
	 */
	protected function options_exist($opts) {
		if (is_array($opts) && !empty($opts['account_id']) && !empty($opts['key'])) return true;
		return false;
	}

	/**
	 * Get the pre configuration template
	 *
	 * @return String - the template
	 */
	public function get_pre_configuration_template() {

		global $updraftplus_admin;

		$classes = $this->get_css_classes(false);
		
		?>
		<tr class="<?php echo $classes . ' ' . 'backblaze_pre_config_container';?>">
			<td colspan="2">
				<img width="434" src="<?php echo UPDRAFTPLUS_URL;?>/images/backblaze.png"><br>
				<?php $updraftplus_admin->curl_check('Backblaze B2', false, 'backblaze'); ?>
			</td>
		</tr>

		<?php
	}

	/**
	 * Get the configuration template
	 *
	 * @return String - the template, ready for substitutions to be carried out
	 */
	public function get_configuration_template() {

		ob_start();

		$classes = $this->get_css_classes();
		
		?>
		
		<tr class="<?php echo $classes;?>">
			<th><?php echo _e('Account ID', 'updraftplus'); ?>:</th>
			<td><input type="text" size="40" data-updraft_settings_test="account_id" <?php $this->output_settings_field_name_and_id('account_id');?> value="{{account_id}}"><br>
			<em><?php echo sprintf(__('Get these settings from %s, or sign up %s.', 'updraftplus'), '<a target="_blank" href="https://secure.backblaze.com/b2_buckets.htm">'.__('here', 'updraftplus').'</a>', '<a target="_blank" href="https://www.backblaze.com/b2/">'.__('here', 'updraftplus').'</a>');?></em></a><br>
			</td>
		</tr>

		<tr class="<?php echo $classes;?>">
			<th><?php _e('Application key', 'updraftplus'); ?>:</th>
			<td><input type="<?php echo apply_filters('updraftplus_admin_secret_field_type', 'password'); ?>" size="40" data-updraft_settings_test="key" <?php $this->output_settings_field_name_and_id('key');?> value="{{key}}" /></td>
		</tr>

		<tr class="<?php echo $classes;?>">
			<th><?php _e('Backup path', 'updraftplus'); ?>:</th>
			<td>/<input type="text" size="19" maxlength="50" placeholder="<?php _e('Bucket name', 'updraftplus');?>" data-updraft_settings_test="bucket_name" <?php $this->output_settings_field_name_and_id('bucket_name');?> value="{{bucket_name}}" />/<input type="text" size="19" maxlength="200" placeholder="<?php _e('some/path', 'updraftplus');?> " data-updraft_settings_test="backup_path" <?php $this->output_settings_field_name_and_id('backup_path');?> value="{{backup_path}}" /><br>
			<em><?php echo '<a target="_blank" href="https://help.backblaze.com/hc/en-us/articles/217666908-What-you-need-to-know-about-B2-Bucket-names">'.__('There are limits upon which path-names are valid. Spaces are not allowed.', 'updraftplus').'</a>';?></em><br>
			</td>
		</tr>
		
		<?php
		
		echo $this->get_test_button_html('Backblaze');
		
		return ob_get_clean();
	}
	
	/**
	 * Get bucket name list array for current storage instance
	 *
	 * @return array Which contains bucket names as element values
	 */
	protected function get_bucket_names_array() {
		$bucket_names = array();
		$storage = $this->get_storage();
		$buckets = $storage->listBuckets();
		if (is_array($buckets)) {
			foreach ($buckets as $bucket) {
				$bucket_names[] = $bucket->getName();
			}
		}
		return $bucket_names;
	}
	
	/**
	 * Checks whether bucket name is valid as per backblaze standards
	 *
	 * @param string $bucket_name Backblaze bucket name
	 * @return boolean If bucket name is valid, it returns true. Otherwise false
	 */
	protected function is_valid_bucket_name($bucket_name) {
		return preg_match('/^(?!b2-)[-0-9a-z]{6,50}$/i', $bucket_name);
	}
}
