<?php
namespace com_webcomand_noisemeter_api\controllers;

use io_comand_util\time;

class v1 extends \io_comand_mvc\controller {
    const PAGE_SIZE = 1000; // size of a page of results, to limit the number of items returned by any API request
    const ROUND_PRECISION = 3;
    const EASTERN_TIMEZONE_OFFSET = '-04:00';
    const DATABASE_TIMEZONE = 'Canada/Eastern';
    const BASE_UUID = '6b4ea822-ebbd-11ee-8dc8-8b3876a5da56';
    const LOGIN_POLICY_UUID = '32b19866-1748-11ef-9272-997273e8313c';
    const REQUEST_METHOD = 'request'; // get, post or request (either)

    private $log = null;
    private $db_timezone = null;
    private $base = null;

    function __construct(array $options) {
        $this->log = new \io_comand_log\log();
        parent::__construct($options);
    }

    private function db_timezone() {
        if($this->db_timezone === null) {
            $this->db_timezone = new \DateTimeZone(self::DATABASE_TIMEZONE);
        }
        return $this->db_timezone;
    }
    private function log_to_response() {
        $events = [];
		    foreach($this->log as $event) {
            $events []= (object)[
                'timestamp' => date('Y-m-d\TH:i:s' . self::EASTERN_TIMEZONE_OFFSET, $event->Timestamp),
                'type' => $event->Type,
                'message' => $event->Message
            ];
		    }
    	  return $events;
    }

    private function set_base($object) {
        if(!$this->base) {
            $this->base = $this->repo()->get_object_by_uuid(self::BASE_UUID,'Base');
        }
        if($this->base) {
            $object->Folders[] = $this->base;
        }
        return $this->base;
    }

    private function headers() {
        header('Access-Control-Allow-Origin: *');
    }

    private function respond(string $type, string $message, int $response_code, array $resposne_data = []) : bool {
        $data = [
            'message' => $message,
            'result' => $type,
            'timestamp' => date('Y-m-d\TH:i:s' . self::EASTERN_TIMEZONE_OFFSET)
        ];
        if($this->log->count() > 0) {
            $data['log'] = $this->log_to_response();
        }
        if($resposne_data) {
            $data = array_merge($data, $resposne_data);
        }
        if($response_code != 200) {
            http_response_code($response_code);
        }
        $this->headers();
        return $this->ajax->{$type}($data);
        //$json = json_encode($data);
        //return $this->send_data($json);
    }

    private function error(string $message, int $response_code = 500, array $resposne_data = []) {
        return $this->respond('error', $message, $response_code, $resposne_data);
    }

    private function ok(string $message, int $response_code = 200, array $resposne_data = []) {
        return $this->respond('ok', $message, $response_code, $resposne_data);
    }

    /**
     * Return TRUE if $mac is a valid mac address like 3D:F2:C9:A6:B3:4F or 3D-F2-C9-A6-B3-4F
     */
    private function is_mac_address(string $mac) : bool {
        return (preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $mac) === 1 ? true : false);
    }

    /**
     * Return a valid database timestamp or false.
     */
    private function get_timestamp($param) {
        $time = $this->request->{self::REQUEST_METHOD}($param);
        if($time === false) {
            return false;
        }

        // use the following line to assume timestamps without a timezone are UTC
        $default_tz = date_default_timezone_get();
        date_default_timezone_set('UTC');
        $time = date_create($time);
        date_default_timezone_set($default_tz);
        if($time === false) {
            return false;
        }

        // change the timezone to our local DB timezone
        $time = $time->setTimezone($this->db_timezone());

        return $time->format('Y-m-d H:i:s');
    }

    /**
     * Display an error if no end point specified. 
     */
    public function web__index() {
        return $this->error('No end point specified.');
    }

    /**
     * parameters:
     *  timestamp
     *  min
     *  max
     *  mean
     *  device
     *  version (optional)
     *  boottime (optional)
     */
    public function web__measurement() {
        $timestamp = $this->get_timestamp('timestamp');
        $min = $this->request->{self::REQUEST_METHOD}('min');
        $max = $this->request->{self::REQUEST_METHOD}('max');
        $mean = $this->request->{self::REQUEST_METHOD}('mean');

        // if the X-tracket-device header is defined, use that, otherwise use the GET or POST device parameter
        $device_id = $this->request->header('X-Tracket-Device');
        if($device_id === FALSE) {
            $device_id = $this->request->{self::REQUEST_METHOD}('device');
            if(!$device_id) {
                return $this->error('No device specified.');
            }
        }

        $version = $this->request->{self::REQUEST_METHOD}('version');
        $boottime = $this->request->{self::REQUEST_METHOD}('boottime');
        //$wifitime = $this->request->{self::REQUEST_METHOD}('wifitime');

        // get the timestamp in the database format for our (the database location) timezone (east coast)
        // NOTE: If no timezone is specified, assume UTC or timezone based on device location?
        // TODO: Should we ensure the timestamp is within 1 week of the current time?
        if($timestamp === false) {
            return $this->error('Missing or invalid timestamp.');
        }

        // ensure required measurements were specified
        foreach(['min','max','mean'] as $prop) {
            if(${$prop} === false || !is_numeric(${$prop})) {
                return $this->error("Missing or invalid $prop.");
            }
        }

        // get the device by ID
        $device = $this->repo()->get_first('FROM NoiseDevice WHERE DeviceID=? ORDER BY OID', ['bind'=>[$device_id]]);
        if(!$device) {
            return $this->error('Unrecognized device.');
        }

        // get the User and Location
        $device_config = $this->repo()->get_first("SELECT User, NoiseLocation FROM NoiseDeviceHistory WHERE Device.OID=? AND RevisionEnd='0000-00-00 00:00:00' ORDER BY OID DESC", ['bind'=>[$device->OID]]);
        if(!$device_config) {
            return $this->error('Unconfigured device: ' . $device->OID);
        } elseif(!$device_config->User) {
            return $this->error('Unconfigured user.');
        //} elseif(!$device_config->NoiseLocation) {
        //    return $this->error('Unconfigured location.');
        }

        // validate the provided Authorization token against the device
        if(!$this->valid_token($device_config)) {
            return $this->invalid_token();
        }

        $measurement = $this->repo()->new_object('NoiseMeasurement');
        $this->set_base($measurement);
        $measurement->Timestamp = $timestamp;
        $measurement->Device = $device;
        $measurement->User = $device_config->User;
        $measurement->NoiseLocation = $device_config->NoiseLocation; // may be NULL
        $measurement->Min = $min;
        $measurement->Max = $max;
        $measurement->Mean = $mean;

        // record the optional uptime based on boottime
        if($boottime !== false) {
            $boottime_date = date_create($boottime);
            if($boottime_date !== false) {
                // get uptime in seconds from current time minus boottime
                $measurement->Uptime = date_create()->getTimestamp() - $boottime_date->getTimestamp();
                $this->log->log_notice("Converting boottime ($boottime) to uptime (" . $measurement->Uptime . ")");
            } elseif(is_numeric($boottime) && $boottime >= 0) {
                $measurement->Uptime = $boottime;
            } else {
                $this->log->log_warning("Invalid boottime ($boottime).  Must be a timestamp or number of seconds.");
            }
        }

        // record the optional Software Version if provided and matches x.x.x format, otherwise silently discard the provided version
        // TODO: we could further validate and more efficiently store and structured the Software Version if we match on a Device Update Version
        if($version !== false && preg_match('/\d{1,5}.\d{1,5}.\d{1,5}?/', $version)) {
            $measurement->SoftwareVersion = $version;
        }

        $approved = $measurement->approve(['VersionNotes' => 'Added from v1 measurement API.']);
        if(!$approved) {
            return $this->error('Could not add measurement.');
        }

        return $this->ok('Added measurement (OID ' . $measurement->OID . ').');
    }

    public function web__measurements() {
        $content_type = $this->request->header('Content-Type');
        if($content_type === FALSE || $content_type !== 'application/json') {
            return $this->error('Missing or invalid Content-Type header.  Must be application/json.');
        }

        // get the measurements from the JSON body of the request
        $json_body = file_get_contents('php://input');
        if(!($data = json_decode($json_body))) {
            return $this->error('Invalid JSON body: ' . json_last_error_msg());
        }
        if(!is_array($data)) {
            return $this->error('JSON must be an array of measurement objects.');
        }

        // if the X-tracket-device header is defined, use that, otherwise use the GET or POST device parameter
        $device_id = $this->request->header('X-Tracket-Device');
        if($device_id === FALSE) {
            return $this->error('No device specified.');
        }

        // get the device by ID
        $device = $this->repo()->get_first('FROM NoiseDevice WHERE DeviceID=? ORDER BY OID', ['bind'=>[$device_id]]);
        if(!$device) {
            return $this->error('Unrecognized device.');
        }

        // get the User and Location
        $device_config = $this->repo()->get_first("SELECT User, NoiseLocation FROM NoiseDeviceHistory WHERE Device.OID=? AND RevisionEnd='0000-00-00 00:00:00' ORDER BY OID DESC", ['bind'=>[$device->OID]]);
        if(!$device_config) {
            return $this->error('Unconfigured device: ' . $device->OID);
        } elseif(!$device_config->User) {
            return $this->error('Unconfigured user.');
        //} elseif(!$device_config->NoiseLocation) {
        //    return $this->error('Unconfigured location.');
        }

        // validate the provided Authorization token against the device
        if(!$this->valid_token($device_config)) {
            return $this->invalid_token();
        }

        $measurement_props = [
            'timestamp' => ['required' => TRUE],
            'min' => ['required' => TRUE, 'numeric' => TRUE],
            'max' => ['required' => TRUE, 'numeric' => TRUE],
            'mean' => ['required' => TRUE, 'numeric' => TRUE],
            'version' => [],
            'boottime' => [],
        ];

        // parse the JSON data and insert measurements
        $OIDs = [];
        foreach($data as $index => $m) {
            if(!is_object($m)) {
                $this->log->log_error("JSON array item at index $index is not a measurement object.  Skipping.");
                continue;
            }
            
            $valid = TRUE;
            foreach($measurement_props as $prop => $options) {
                if(($options['required'] ?? FALSE) === TRUE && !property_exists($m, $prop)) {
                    $this->log->log_error("JSON array object at index $index is missing required property: $prop");
                    $valid = FALSE;
                    break;
                }
                if(($options['numeric'] ?? FALSE) === TRUE && !is_numeric($m->{$prop})) {
                    return $this->error("Invalid $prop.  Must be numeric.");
                }
            }
            if(!$valid) {
                continue;
            }

            $measurement = $this->repo()->new_object('NoiseMeasurement');
            $this->set_base($measurement);
            $measurement->Timestamp = $m->timestamp;
            $measurement->Device = $device;
            $measurement->User = $device_config->User;
            $measurement->NoiseLocation = $device_config->NoiseLocation; // may be NULL
            $measurement->Min = $m->min;
            $measurement->Max = $m->max;
            $measurement->Mean = $m->mean;
    
            // record the optional uptime based on boottime
            if(property_exists($m, 'boottime')) {
                $boottime_date = date_create($m->boottime);
                if($boottime_date !== false) {
                    // get uptime in seconds from current time minus boottime
                    $measurement->Uptime = date_create()->getTimestamp() - $boottime_date->getTimestamp();
                    $this->log->log_notice("Converting boottime (" . $m->boottime . ") to uptime (" . $measurement->Uptime . ")");
                } elseif(is_numeric($m->boottime) && $m->boottime >= 0) {
                    $measurement->Uptime = $m->boottime;
                } else {
                    $this->log->log_warning("Invalid boottime (" . $m->boottime . ").  Must be a timestamp or number of seconds.");
                }
            }
    
            // record the optional Software Version if provided and matches x.x.x format, otherwise silently discard the provided version
            // TODO: we could further validate and more efficiently store and structured the Software Version if we match on a Device Update Version
            if(property_exists($m, 'version') && preg_match('/\d{1,5}.\d{1,5}.\d{1,5}?/', $m->version)) {
                $measurement->SoftwareVersion = $m->version;
            }

            $approved = $measurement->approve(['VersionNotes' => 'Added from v1 measurements API.']);
            if(!$approved) {
                $this->log->log_error('Could not add measurement.');
                continue;
            }

            $OIDs []= $measurement->OID;
        }

        $plural = (count($OIDs)==1 ? '' : 's');
        return $this->ok('Added ' . count($OIDs) . ' measurement' . $plural . (count($OIDs)>0 ? ' (OID' . $plural . ': ' . implode(', ', $OIDs) . ')' : '') . '.');
    }

    /**
     * Get Noise Locations
     */
    public function web__locations($location_id = null, $endpoint = null) {
        // get the page
        $page = $this->request->{self::REQUEST_METHOD}('page');
        $offset = ($page !== FALSE && is_numeric($page) ? intval($page) * self::PAGE_SIZE : 0);

        // get the location ID as an int or null
        $location_id = ($location_id !== NULL && is_numeric($location_id) ? intval($location_id) : null);
        if($location_id !== null && $endpoint == 'noise') {
            return $this->get_location_noise($location_id, $offset);
        }

        $query =
            "SELECT OID AS id, Label as label, Latitude AS latitude, Longitude AS longitude, Radius AS radius, " .
            "MAX(IF(@(NoiseLocation)NoiseDeviceHistory.RevisionEnd IN (NULL,'0000-00-00 00:00:00'),1,0)) AS active " .
            "FROM NoiseLocation " .
            ($location_id !== null ? "WHERE OID=$location_id " : "") .
            "GROUP BY OID " .
            "ORDER BY OID " .
            "LIMIT $offset," . self::PAGE_SIZE;

        $query_start = time::get_time();
        $locations = $this->repo()->get_rows($query);
        $locations = $locations->as_array();
        foreach($locations as &$location) {
            $location['active'] = $location['active'] ? TRUE : FALSE;
        }
        $query_time = time::get_duration($query_start);
        $this->headers();
        return $this->ajax->ok([
            'locations' => $locations,
            'query' => $query,
            'query_time' => $query_time
        ]);
    }

    private function get_location_noise(int $location_id, int $offset) {
        $select_timestamp = "DATE_FORMAT(Timestamp, '%Y-%m-%dT%H:%i:%s" . self::EASTERN_TIMEZONE_OFFSET . "') AS timestamp, ";
        $aggregate = false;
        $group_by = '';
        $order_by = "ORDER BY Timestamp, OID ";
        $limit = "LIMIT $offset," . self::PAGE_SIZE;

        $start = $this->get_timestamp('start');
        $end = $this->get_timestamp('end');
        $granularity = $this->request->{self::REQUEST_METHOD}('granularity');
        if($granularity == 'hourly') {
            $hourly_timestamp = "DATE_FORMAT(Timestamp, '%Y-%m-%dT%H:00:00" . self::EASTERN_TIMEZONE_OFFSET . "')";
            $select_timestamp = $hourly_timestamp . ' AS timestamp, ';
            $aggregate = true;
            $group_by = "GROUP BY $hourly_timestamp ";
        } elseif($granularity == 'life-time') {
            $select_timestamp = 'MIN(Timestamp) AS start, MAX(Timestamp) AS end, COUNT() AS count, ';
            $order_by = $limit = '';
            $aggregate = true;
        }

        $query =
            "SELECT " . $select_timestamp .
            ($aggregate ? "ROUND(MIN(Min)," . self::ROUND_PRECISION . ") AS min, ROUND(MAX(Max)," . self::ROUND_PRECISION . ") AS max, ROUND(AVG(Mean)," . self::ROUND_PRECISION . ") AS mean " : "ROUND(Min," . self::ROUND_PRECISION . ") AS min, ROUND(Max," . self::ROUND_PRECISION . ") AS max, ROUND(Mean," . self::ROUND_PRECISION . ") AS mean ") .
            "FROM NoiseMeasurement " .
            "WHERE NoiseLocation.OID=$location_id " .
            ($start ? " AND Timestamp>='$start' " : "") .
            ($end ? " AND Timestamp<='$end' " : "") .
            $group_by .
            $order_by .
            $limit;

        $query_start = time::get_time();
        $measurements = $this->repo()->get_rows($query);
        $query_time = time::get_duration($query_start);

        $this->headers();
        return $this->ajax->ok([
            'measurements' => $measurements->as_array(),
            'query' => $query,
            'query_time' => $query_time
        ]);
    }

    /**
     * Register the device and return a token.
     */
    private function register($device) {
        $email = $this->request->header('X-Tracket-Email');
        if($email === FALSE) {
            $email = $this->request->{self::REQUEST_METHOD}('email');
        }
        if(!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->error('Invalid email.');
        }

        // see if there is already a user with this email address
        $user = $this->repo()->get_first('FROM NoiseUser WHERE Email=? AND Active ORDER BY OID', ['bind'=>[$email]]);
        if(!$user) {
            // there is not an existing user with the email address, so add a new user now
            $user = $this->repo()->new_object('NoiseUser');
            $this->set_base($user);
            $user->Username = $email;
            $user->Email = $email;
            $user->Active = true;
            $user->DateRegistered = substr(time::get_db_timestamp(), 0, 10);
            if(!$user->approve(['VersionNotes'=>'Added by API device registration.'])) {
                return $this->error('Email does not match a user.  New user could not be created.');
            }

            // get the tRacket Login Policy
            $login_policy = $this->repo()->get_object_by_uuid(self::LOGIN_POLICY_UUID, 'LoginPolicy');
            if($login_policy) {
                // add the user to the Login Policy
                $login_policy->Users []= $user;
                if(!$login_policy->approve(['VersionNotes'=>'Updated by API device registration for user: $email.'])) {
                    $this->log->log_error('User added, but could not be added to Login Policy.');
                }
            } else {
                $this->log->log_error('User added, but could not get Login Policy.');
            }
        }

        // create Device History for this User and Device, if one doesn't already exist
        $device_config = $this->repo()->get_first("FROM NoiseDeviceHistory WHERE Device.OID=? AND User.OID=? AND RevisionEnd='0000-00-00 00:00:00' ORDER BY OID DESC", ['bind'=>[$device->OID, $user->OID]]);
        if(!$device_config) {
            $now = time::get_timestamp();

            // if this device is currently registered to a different user, expire that registration
            $device_history = $this->repo()->get_first("FROM NoiseDeviceHistory WHERE Device.OID=? AND RevisionEnd='0000-00-00 00:00:00' ORDER BY OID DESC", ['bind'=>[$device->OID]]);
            if($device_history) {
                // expire current device user relationship
                $device_history->RevisionEnd = $now;
                if(!$device_history->approve(['VersionNote'=>'Set Revision End because new user registered device via API.'])) {
                    // if we could not approve the expiration of the existing device/user relationship, report an error
                    return $this->error('Could not add device history.');
                }
            }

            // add new device user relationship
            $device_config = $this->repo()->new_object('NoiseDeviceHistory');
            $this->set_base($device_config);
            $device_config->RevisionStart = $now;
            $device_config->Device = $device;
            $device_config->User = $user;
            if(!$device_config->approve()) {
                // if we could not approve the user token for some reason, report an error
                return $this->error('Could not add device history.');
            }
        }

        // if this user already has a valid token, provide that, otherwise add one now
        $user_token = $this->repo()->get_first("SELECT Token FROM UserToken WHERE User.OID=? AND Active AND ValidStart<=NOW() AND (ValidEnd='0000-00-00 00:00:00' OR ValidEnd>=NOW())", ['bind'=>[$user->OID]]);
        if(!$user_token) {
            // create a User Token (will be active and set a token for us automatically)
            $user_token = $this->repo()->new_object('UserToken');
            $user_token->User = $user;
            $user_token->ValidStart = time::get_timestamp();
            if(!$user_token->approve()) {
                // if we could not approve the user token for some reason, report an error
                return $this->error('Could not create user token.');
            }
        }

        // Send the registered user the registration confirmation email
        $this->email_registration_confirmation($email);

        // TOOD: should we see if the device is already registered to a different user, and if so fail?
        return $this->ok('Device registered.', 200, ['token' => $user_token->Token]);
    }

    private function email_registration_confirmation($email) {
        // get the Email Template
        $email_template = $this->repo()->get_object_by_uuid('70502e3a-3b16-11ef-9272-997273e8313c', 'EmailTemplate');
        if(!$email_template) {
            // TODO: notify an admin that we couldn't email this confirmation
            return FALSE;
        }

        $sent = \io_comand_email\mail::mail($email, $email_template->Subject, $email_template->Message, [
            'From' => $email_template->From,
            'Content-Type' => 'text/html; charset=utf-8'
        ]);
        if(!$sent) {
            // TODO: notify an admin that we couldn't email this confirmation
            return FALSE;
        }
        
        return TRUE;
    }

    /**
     * Set a device location.
     * 
     * NOTE: User is already authorized before this is called.
     */
    private function set_location($device, $device_config) {
        $latitude = $this->request->{self::REQUEST_METHOD}('latitude');
        $longitude = $this->request->{self::REQUEST_METHOD}('longitude');
        $radius = $this->request->{self::REQUEST_METHOD}('radius');
        $public_label = $this->request->{self::REQUEST_METHOD}('publicLabel');
        $private_label = $this->request->{self::REQUEST_METHOD}('privateLabel');

        // ensure required measurements were specified
        foreach(['latitude','longitude','radius'] as $prop) {
            if(${$prop} === false || !is_numeric(${$prop})) {
                return $this->error("Missing or invalid $prop.  Must be numeric.");
            }
        }

        $location = null;
        $locations = $this->repo()->get("FROM NoiseLocation WHERE Latitude=? AND Longitude=? AND Radius=? ORDER BY OID DESC", ['bind'=>[$latitude, $longitude, $radius]]);
        foreach($locations as $existing_location) {
            // see if this location is owned by this user, just use the existing location and update if needed
            $history = $this->repo()->get_first("SELECT Location FROM NoiseDeviceHistory WHERE Device.OID=? AND Location.OID=? AND User.OID=? ORDER BY OID DESC", ['bind'=>[$device->OID, $existing_location->OID, $device_config->User->OID]]);
            if($history) {
                // if the public or private label has changed, update it
                if($existing_location->Label != $public_label || $existing_location->PrivateLabel != $private_label) {
                    $existing_location->Label = $public_label;
                    $existing_location->PrivateLabel = $private_label;
                    if(!$existing_location->approve()) {
                        return $this->error('Could not update existing location.');
                    }
                    $this->log->log_notice('Existing location public and/or private label updated.');
                }
                $location = $existing_location;
                break;
            }
        }

        // location doesn't already exist, so add a new one
        if(!$location) {
            $location = $this->repo()->new_object('NoiseLocation');
            $this->set_base($location);
            $location->Label = $public_label;
            $location->PrivateLabel = $private_label;
            $location->Latitude = $latitude;
            $location->Longitude = $longitude;
            $location->Radius = $radius;
            if(!$location->approve()) {
                return $this->error('Could not add location.');
            }
        }

        // if the location is already set for this device, nothing to do
        if($device_config->Location && $device_config->Location->OID == $location->OID) {
            return $this->ok('Device location was already set.');
        }

        // add the new device history and location
        $new_config = clone $device_config;
        $new_config->RevisionStart = time::get_timestamp();
        $new_config->Location = $location;

        // expire the previous device history
        $device_config->RevisionEnd = $new_config->RevisionStart;

        // TODO: should do this to make updates in a single transaction (both succeed or fail)
        // if(!$this->new_collection($new_config, $device_config)->approve()) {
        //     return $this->error('Could not set location.');
        // }
        if(!$new_config->approve()) {
            return $this->error('Could not set location.');
        }
        if(!$device_config->approve()) {
            return $this->error('Could not expire previous location.');
        }

        return $this->ok('New device location set.');
    }

    private function valid_token($device_config) {
        // make sure a valid device config with an active User was passed in
        if(!$device_config || !$device_config->User || !$device_config->User->Active) {
            // no valid device history or user (user must exist for a token to exist)
            \comand::log_warning('No Device History with User for device (OID ' . $device_config->Device->OID . ').');
            return FALSE;
        }

        // validate device token in Authorization header
        $authorization = $this->request->header('Authorization');
        if($authorization === FALSE || !preg_match('/^Token ([a-z0-9]+)$/i', $authorization, $matches)) {
            \comand::log_warning('User (OID ' . $device_config->User->OID . ') invalid Authorization header (' . $authorization . ')');
            return FALSE;
        }

        // require a valid token associated with this device
        $token = $matches[1];

        $user_token = $this->repo()->get_first("SELECT Token FROM UserToken WHERE User.OID=? AND Token=? AND Active AND ValidStart<=NOW() AND (ValidEnd='0000-00-00 00:00:00' OR ValidEnd>=NOW())", ['bind'=>[$device_config->User->OID, $token]]);
        if(!$user_token) {
            // no matching active token
            \comand::log_warning('User (OID ' . $device_config->User->OID . ') does not have an active matching UserToken for token: ' . $token);
            return FALSE;
        }

        return TRUE;
    }

    private function invalid_token() {
        // TODO: we should lockout an IP after X failed attempts to prevent hacks
        // Could use webCOMAND Login Policy and related functionality for this and more.
        return $this->error('Invalid authorization token.');
    }

    /**
     * Endpoints to get and set device information.
     * 
     * These endpoints all require a valid user token, except for device/register, which produces a token to be used for subsequent requests.
     */
    public function web__device($endpoint = null) {
        // define device endpoints and if they require authentication
        $device_endpoints = [
            'register' => FALSE,
            'set_location' => TRUE
        ];

        if($endpoint === null || !array_key_exists($endpoint, $device_endpoints)) {
            return $this->error('Invalid device endpoint.');
        }
        $requires_authorization = $device_endpoints[$endpoint];

        // get the device by ID (MD5 hash of MAC Address)
        $device_id = $this->request->header('X-Tracket-Device');
        if($device_id === FALSE) {
            $device_id = $this->request->{self::REQUEST_METHOD}('device');
        }
        $device = $this->repo()->get_first('FROM NoiseDevice WHERE DeviceID=? ORDER BY OID', ['bind'=>[$device_id]]);
        if(!$device) {
            return $this->error('Unrecognized device.');
        }

        // if this endpoint does not require authentication, call the corresponding method and pass the device
        if(!$requires_authorization) {
            return $this->{$endpoint}($device);
        }

        // get the User and Location of this device, if one exists
        $device_config = $this->repo()->get_first("SELECT User, Location FROM NoiseDeviceHistory WHERE Device.OID=? AND RevisionEnd='0000-00-00 00:00:00' ORDER BY OID DESC", ['bind'=>[$device->OID]]);
        if(!$this->valid_token($device_config)) {
            return $this->invalid_token();
        }

        return $this->{$endpoint}($device, $device_config);
    }

    /**
     * Endpoint to get the latest software version information
     * 
     * These endpoints all require a valid user token, except for device/register, which produces a token to be used for subsequent requests.
     */
    public function web__software($endpoint = null) {
        if($endpoint !== 'latest') {
            return $this->error('Invalid software endpoint.');
        }

        $publication_doid = 66555;
        $latest_update = $this->repo()->get_first("SELECT DATE_FORMAT(ReleaseTime, '%Y-%m-%dT%H:%i:%s" . self::EASTERN_TIMEZONE_OFFSET . "') AS ReleaseTime, Version, @(Object)PublicationRecord.Filename AS URL FROM DeviceUpdate WHERE @(Object)PublicationRecord.PublicationDOID=? ORDER BY ReleaseTime DESC LIMIT 1", ['bind'=>[$publication_doid]]);
        if(!$latest_update) {
            return $this->error('Unable to find latest update information.');
        }

        $publication = $this->repo()->get_object_by_doid($publication_doid);
        if(!$publication) {
            return $this->error('Unable to find publication information.');
        }
        

        return $this->ok('Found latest software', 200, [
            'version' => $latest_update->Version,
            'releaseTime' => $latest_update->ReleaseTime,
            'url' => rtrim($publication->URL,'/') . '/' . ltrim($latest_update->URL,'/')
        ]);
    }
}
