<?php

// @TODO: DEFINE PARAMS IN THE VARIOUS METHODS

class MewsConnector
{
    private $url;
    private $auth;
    private $config;
    private $serviceIds = [];
    
    function __construct($environment = 'demo', $configFile = null)
    {
        // Load configuration
        if ($configFile && file_exists($configFile)) {
            $this->config = require $configFile;
        } else {
            // Try to load default config file
            $defaultConfigPath = __DIR__ . '/mews_config.php';
            if (file_exists($defaultConfigPath)) {
                $this->config = require $defaultConfigPath;
                // Use environment from config if not specified
                if (!$environment && isset($this->config['mews']['environment'])) {
                    $environment = $this->config['mews']['environment'];
                }
            }
        }
        
        // Set service IDs from config or use defaults
        if (isset($this->config['mews']['service_ids'])) {
            $this->serviceIds = $this->config['mews']['service_ids'];
        } else {
            $this->serviceIds = [
                '4444f78b-ca4f-4802-be17-acc501177efe', // Id of the Stay Service
                'af7a7d60-c9ba-402c-a761-b1b700fb3106', // Id of the DayUse Service
                '63952eb6-5908-4b66-9955-acd100d550d6'  // Id of the Employee Rooms Service
            ];
        }
        // Initialize based on environment
        $this->initializeEnvironment($environment);
    }
    
    /**
     * Initialize the Mews connector for the specified environment
     *
     * @param string $env Environment ('demo', 'cert', 'prod')
     * @throws Exception If configuration or INI file cannot be loaded
     */
    private function initializeEnvironment($environment)
    {
        // Set API URL from config or use defaults
        if (isset($this->config['mews']['api_urls'][$environment])) {
            $this->url = $this->config['mews']['api_urls'][$environment];
        } else {
            // Fallback to hardcoded URLs
            switch ($environment) {
                case 'prod':
                    $this->url = "https://api.mews.com/api/connector/v1";
                    break;
                case 'demo':
                case 'cert':
                    $this->url = "https://api.mews-demo.com/api/connector/v1";
                    break;
                default:
                    throw new Exception("Invalid environment: $environment. Must be 'demo', 'cert', or 'prod'");
            }
        }

        $iniFile = $this->getIniFilePath($environment);

        // Load authentication from INI file
        try {
            if (!file_exists($iniFile)) {
                throw new Exception("INI file not found: $iniFile");
            }
            
            $this->auth = parse_ini_file($iniFile);
            
            if (!$this->auth) {
                throw new Exception("Failed to parse INI file: $iniFile");
            }
            
            // Validate required authentication fields
            $requiredFields = ['ClientToken', 'AccessToken', 'Client'];
            foreach ($requiredFields as $field) {
                if (!isset($this->auth[$field]) || empty($this->auth[$field])) {
                    throw new Exception("Missing required field '$field' in INI file: $iniFile");
                }
            }
            
        } catch (Exception $e) {
            error_log("Mews Connector Error: " . $e->getMessage());
            throw new Exception("Error loading Mews configuration: " . $e->getMessage());
        }
    }
    
    /**
     * Get the INI file path for the specified environment
     *
     * @param string $env Environment name
     * @return string INI file path
     */
    private function getIniFilePath($environment)
    {
        // Check if custom INI path is defined in config
        if (isset($this->config['mews']['ini_paths'][$environment])) {
            return $this->config['mews']['ini_paths'][$environment];
        }
        
        // Use default paths
        return "/ini/{$environment}_mews.ini";
    }
    
    /**
     * Get configuration value
     *
     * @param string $key Configuration key (dot notation supported)
     * @param mixed $default Default value if key not found
     * @return mixed Configuration value
     */
    public function getConfig($key, $default = null)
    {
        $keys = explode('.', $key);
        $value = $this->config;
        
        foreach ($keys as $k) {
            if (is_array($value) && isset($value[$k])) {
                $value = $value[$k];
            } else {
                return $default;
            }
        }
        
        return $value;
    }
    
    /**
     * Get the API URL
     *
     * @return string The Mews API URL
     */
    public function getApiUrl()
    {
        return $this->url;
    }

    /**
     * ====================================
     *          HELPER FUNCTIONS
     * ====================================
     */

    /**
     * Sets up a request by combining the base URL, endpoint, and authentication data.
     *
     * @param string $endpoint The endpoint to append to the base URL.
     * @param array|null $params Optional parameters to merge with the authentication data.
     * @return array The decoded JSON response from the request.
     */
    public function sendRequest($endpoint, $params = NULL)
    {
        // Construct the full URL by appending the endpoint to the base URL.
        $url = "{$this->url}{$endpoint}";

        // Initialize the payload with the authentication data.
        $payload = $this->auth;

        // Set the default limitation count to 1000.
        $payload["Limitation"] = ["Count" => 1000];

        // If parameters were provided, merge them with the payload.
        if ($params) {
            $payload = array_replace($payload, $params);
        }

        // Perform the request and return the response.
        $response = $this->performRequest($url, $payload);
        return $response;
    }

    /**
     * Performs a HTTP POST request to the specified endpoint with the given payload.
     *
     * @param string $endpoint The URL of the endpoint to send the request to.
     * @param array $payload The data to send as the payload of the request.
     * @return array The decoded JSON response from the request.
     */
    public function performRequest($endpoint, $payload)
    {
        // Encode the payload in JSON
        $json = json_encode($payload);

        // Initialize a cURL session
        $curl = curl_init();

        // Set the URL to request
        curl_setopt($curl, CURLOPT_URL, $endpoint);

        // Return the response as a string instead of outputting it
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        // Declare this is a POST request
        curl_setopt($curl, CURLOPT_POST, true);

        // Declare the payload of the post request
        curl_setopt($curl, CURLOPT_POSTFIELDS, $json);

        // Set the type of content the post request is sending in the header
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));

        // Execute the cURL request and get the response
        $response = curl_exec($curl);
        $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if ($httpcode != 200) {
            echo $httpcode, PHP_EOL;
            var_dump($response);
            exit;
        }
        // Decode the response from JSON and return it
        $response = json_decode($response, true);

        return $response;
    }

    /**
     * ====================================
     *            RESERVATIONS
     * ====================================
     */

    /**
     * Retrieves all reservations from the Mews API.
     *
     * @param array|null $params Optional parameters to merge with the authentication data.
     * @return array The decoded JSON response from the request.
     */
    public function getReservations($params = NULL)
    {
        $endpoint = "/reservations/getAll/2023-06-06";

        // If parameters are provided, perform a request with the parameters
        if ($params) {
            $output = $this->sendRequest($endpoint, $params);
            return $output;
        }

        // If no parameters are provided, perform a request without parameters
        $output = $this->sendRequest($endpoint);
        return $output;
    }

    /**
     * Retrieves all reservation groups from the Mews API.
     *
     * @param array|null $params Optional parameters to merge with the authentication data.
     * @return array The decoded JSON response from the request.
     */
    public function getReservationGroups($params = NULL)
    {
        $endpoint = "/reservationGroups/getAll";

        // If parameters are provided, perform a request with the parameters
        if ($params) {
            // Set up the request with the endpoint and parameters
            $output = $this->sendRequest($endpoint, $params);
            // Return the response from the request
            return $output;
        }

        // If no parameters are provided, perform a request without parameters
        // Set up the request with the endpoint and no parameters
        $output = $this->sendRequest($endpoint);
        // Return the response from the request
        return $output;
    }

    /**
     * ====================================
     *            RESOURCES
     * ====================================
     */

    /**
     * Retrieves all resources from the Mews API.
     *
     * @param array|null $params Optional parameters to merge with the authentication data.
     * @return array The decoded JSON response from the request.
     */
    public function getResources($params = NULL)
    {
        $endpoint = "/resources/getAll";

        // If parameters are provided, perform a request with the parameters
        if ($params) {
            $output = $this->sendRequest($endpoint, $params);
            return $output;
        }

        // If no parameters are provided, perform a request without parameters
        $output = $this->sendRequest($endpoint);
        return $output;
    }

    /**
     * Retrieves all resource categories from the Mews API.
     *
     * @param array|null $params Optional parameters to merge with the authentication data.
     * @return array The decoded JSON response from the request.
     */
    public function getResourceCategories($params = NULL)
    {
        $endpoint = "/resourceCategories/getAll";

        // If parameters are provided, perform a request with the parameters
        if ($params) {
            // Set up the request with the endpoint and parameters
            $output = $this->sendRequest($endpoint, $params);
            // Return the response from the request
            return $output;
        }

        // If no parameters are provided, perform a request without parameters
        // Set up the request with the endpoint and no parameters
        $output = $this->sendRequest($endpoint);
        // Return the response from the request
        return $output;
    }

    /**
     * Retrieves all resource category assignments from the Mews API.
     *
     * @param array|null $params Optional parameters to merge with the authentication data.
     * @return array The decoded JSON response from the request.
     */
    public function getResourceCategoriesAssignments($params = NULL)
    {
        $endpoint = "/resourceCategoriesAssignments/getAll";

        // If parameters are provided, perform a request with the parameters
        if ($params) {
            // Set up the request with the endpoint and parameters
            $output = $this->sendRequest($endpoint, $params);
            // Return the response from the request
            return $output;
        }

        // If no parameters are provided, perform a request without parameters
        // Set up the request with the endpoint and no parameters
        $output = $this->sendRequest($endpoint);
        // Return the response from the request
        return $output;
    }

    /**
     * ====================================
     *          PRODUCTS & ORDERS
     * ====================================
     */

    /**
     * Retrieves all products from the Mews API.
     *
     * @param array|null $params Optional parameters to merge with the authentication data.
     *                           This array can contain the following keys:
     *                           - ServiceIds: An array of service IDs to filter the products by.
     *                           - Limitation: An array specifying the number of records to retrieve.
     *                           Example: ['Count' => 1000]
     * @return array The decoded JSON response from the request.
     */
    public function getAllProducts($params = NULL)
    {
        $endpoint = "/products/getAll";

        // Set the payload with the service ID
        $payload["ServiceIds"] = $this->serviceIds;

        // If parameters are provided, merge them with the payload
        if ($params) {
            $payload = array_replace($payload, $params);
        }

        // Set up the request with the endpoint and payload
        $output = $this->sendRequest($endpoint, $payload);

        // Return the response from the request
        return $output;
    }

    /**
     * Retrieves all product categories from the Mews API.
     *
     * @param array|null $params Optional parameters to merge with the authentication data.
     *                           This array can contain the following keys:
     *                           - ServiceIds: An array of service IDs to filter the product categories by.
     * @return array The decoded JSON response from the request.
     */
    public function getAllProductCategories($params = NULL)
    {
        $endpoint = "/productCategories/getAll";

        // Set the payload with the service ID
        $payload["ServiceIds"] = $this->serviceIds;

        // If parameters are provided, merge them with the payload
        if ($params) {
            $payload = array_replace($payload, $params);
        }

        // Set up the request with the endpoint and payload
        $output = $this->sendRequest($endpoint, $payload);

        // Return the response from the request
        return $output;
    }

    /**
     * Retrieves all order items from the Mews API.
     *
     * @param array|null $params Optional parameters to merge with the authentication data.
     *                           This array can contain the following keys:
     *                           - ServiceIds: An array of service IDs to filter the order items by.
     * @return array The decoded JSON response from the request.
     */
    public function getOrderItems($params = NULL)
    {
        // Set the endpoint URL
        $endpoint = "/orderItems/getAll";

        // Set the payload with the service ID
        $payload["ServiceIds"] = $this->serviceIds;

        // If parameters are provided, merge them with the payload
        if ($params) {
            // Merge the given parameters with the payload
            $payload = array_replace($payload, $params);
        }

        // Set up the request with the endpoint and payload
        $output = $this->sendRequest($endpoint, $payload);

        // Return the response from the request
        return $output;
    }

    /**
     * ====================================
     *              PAYMENTS
     * ====================================
     */

    // @TODO Implement this section if needed

    /**
     * ====================================
     *         WIFI AUTHENTICATION
     * ====================================
     */

    /**
     * Validates guest credentials for WiFi access
     * Checks if a guest with the given room number and surname has an active reservation for today
     *
     * @param string $roomNumber The room number to check
     * @param string $guestSurname The guest's surname
     * @return array|false Returns reservation data if valid, false otherwise
     */
    public function validateGuestForWifi($roomNumber, $guestSurname)
    {
        try {
            // Get today's date in UTC format
            $today = date('Y-m-d\TH:i:s\Z');
            $startOfDay = date('Y-m-d\T00:00:00\Z');
            $endOfDay = date('Y-m-d\T23:59:59\Z');

            // Get reservations for today
            $params = [
                'TimeFilter' => [
                    'StartUtc' => $startOfDay,
                    'EndUtc' => $endOfDay
                ],
                'States' => ['Confirmed', 'Started', 'Processed'] // Only active reservations
            ];

            $reservations = $this->getReservations($params);
            
            if (!isset($reservations['Reservations'])) {
                return false;
            }

            // Get resources (rooms) to map resource IDs to room numbers
            $resources = $this->getResources();
            $roomMap = [];
            
            if (isset($resources['Resources'])) {
                foreach ($resources['Resources'] as $resource) {
                    if (isset($resource['Number']) && isset($resource['Id'])) {
                        $roomMap[$resource['Id']] = $resource['Number'];
                    }
                }
            }

            // Check each reservation
            foreach ($reservations['Reservations'] as $reservation) {
                // Check if reservation is for the requested room
                $reservationRoomNumber = null;
                if (isset($reservation['AssignedResourceId']) && isset($roomMap[$reservation['AssignedResourceId']])) {
                    $reservationRoomNumber = $roomMap[$reservation['AssignedResourceId']];
                }

                // Skip if room doesn't match
                if ($reservationRoomNumber !== $roomNumber) {
                    continue;
                }

                // Check if reservation is active for today
                $startDate = strtotime($reservation['StartUtc']);
                $endDate = strtotime($reservation['EndUtc']);
                $todayTimestamp = strtotime($today);

                if ($todayTimestamp >= $startDate && $todayTimestamp <= $endDate) {
                    // Get customer details to check surname
                    if (isset($reservation['CustomerId'])) {
                        $customerDetails = $this->getCustomerDetails($reservation['CustomerId']);
                        
                        if ($customerDetails && $this->matchesSurname($customerDetails, $guestSurname)) {
                            return [
                                'valid' => true,
                                'room_number' => $roomNumber,
                                'guest_surname' => $guestSurname,
                                'check_in' => date('Y-m-d', $startDate),
                                'check_out' => date('Y-m-d', $endDate),
                                'reservation_id' => $reservation['Id'],
                                'customer_id' => $reservation['CustomerId']
                            ];
                        }
                    }
                }
            }

            return false;

        } catch (Exception $e) {
            error_log("Mews WiFi validation error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Gets customer details by customer ID
     *
     * @param string $customerId The customer ID
     * @return array|false Customer details or false if not found
     */
    private function getCustomerDetails($customerId)
    {
        try {
            $endpoint = "/customers/getAll";
            
            $params = [
                'CustomerIds' => [$customerId]
            ];

            $response = $this->sendRequest($endpoint, $params);
            
            if (isset($response['Customers']) && count($response['Customers']) > 0) {
                return $response['Customers'][0];
            }

            return false;

        } catch (Exception $e) {
            error_log("Error getting customer details: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Checks if customer surname matches the provided surname
     *
     * @param array $customer Customer data from Mews
     * @param string $surname Surname to match
     * @return bool True if surnames match
     */
    private function matchesSurname($customer, $surname)
    {
        // Check LastName field
        if (isset($customer['LastName'])) {
            if (strcasecmp(trim($customer['LastName']), trim($surname)) === 0) {
                return true;
            }
        }

        // Check Name field as fallback
        if (isset($customer['Name'])) {
            $nameParts = explode(' ', trim($customer['Name']));
            $customerSurname = end($nameParts); // Last part of name
            
            if (strcasecmp(trim($customerSurname), trim($surname)) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gets all current reservations for today (for admin interface)
     *
     * @return array Array of current reservations
     */
    public function getCurrentReservations()
    {
        try {
            $startOfDay = date('Y-m-d\T00:00:00\Z');
            $endOfDay = date('Y-m-d\T23:59:59\Z');

            $params = [
                'TimeFilter' => [
                    'StartUtc' => $startOfDay,
                    'EndUtc' => $endOfDay
                ],
                'States' => ['Confirmed', 'Started', 'Processed']
            ];

            $reservations = $this->getReservations($params);
            $resources = $this->getResources();
            
            // Build room mapping
            $roomMap = [];
            if (isset($resources['Resources'])) {
                foreach ($resources['Resources'] as $resource) {
                    if (isset($resource['Number']) && isset($resource['Id'])) {
                        $roomMap[$resource['Id']] = $resource['Number'];
                    }
                }
            }

            $currentReservations = [];
            
            if (isset($reservations['Reservations'])) {
                foreach ($reservations['Reservations'] as $reservation) {
                    if (isset($reservation['AssignedResourceId']) && isset($roomMap[$reservation['AssignedResourceId']])) {
                        $roomNumber = $roomMap[$reservation['AssignedResourceId']];
                        
                        // Get customer details
                        $customer = null;
                        if (isset($reservation['CustomerId'])) {
                            $customer = $this->getCustomerDetails($reservation['CustomerId']);
                        }

                        $currentReservations[] = [
                            'room_number' => $roomNumber,
                            'guest_surname' => $customer['LastName'] ?? $customer['Name'] ?? 'Unknown',
                            'check_in' => date('Y-m-d', strtotime($reservation['StartUtc'])),
                            'check_out' => date('Y-m-d', strtotime($reservation['EndUtc'])),
                            'reservation_id' => $reservation['Id']
                        ];
                    }
                }
            }

            return $currentReservations;

        } catch (Exception $e) {
            error_log("Error getting current reservations: " . $e->getMessage());
            return [];
        }
    }
}