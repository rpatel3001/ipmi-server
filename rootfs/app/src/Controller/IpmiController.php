<?php

/**
 * @author Adrian Teodorescu (ateodorescu@gmail.com)
 *
 * This class allows interaction with `ipmitool` executable.
 */

namespace App\Controller;

use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Process\Process;
class IpmiController
{
    private array $sensorTypes = [
        'temperature' => 'degrees C',
        'voltage' => 'Volts',
        'fan' => 'RPM',
        'power' => 'Watts',
        'current' => 'Amps'
    ];
    private array $debug = [];
    const COMMAND_TIMEOUT = 600;
    const DEFAULT_PORT = 623;
    const DEFAULT_USERNAME = 'ADMIN';
    const DEFAULT_INTERFACE = 'lanplus';

    public function index(Request $request): JsonResponse
    {
        $info = $this->getDeviceInfo($request);

        if ($info['success']) {
            $sensors = $this->getSensors($request);

            if ($sensors['success']) {
                $info = array_merge($info, $sensors);
            }
        }

        $info['debug'] = implode("\n", $this->debug);

        return new JsonResponse($info);
    }

    public function command(Request $request): JsonResponse
    {
        $cmd = str_getcsv($request->get('params', ''), ' ', '"', '');
        array_unshift($cmd, 'ipmitool');
        $ret = $this->runCommand($cmd);
        $done = ($ret !== false);

        return new JsonResponse([
            'success' => $done,
            'output' => $done ? $ret : implode("\n", $this->debug)
        ]);
    }

    public function sensors(Request $request): JsonResponse
    {
        return new JsonResponse($this->getSensors($request));
    }

    public function power_on(Request $request): JsonResponse
    {
        return $this->runChassisCommand($request, 'on');
    }

    public function power_off(Request $request): JsonResponse
    {
        return $this->runChassisCommand($request, 'off');
    }

    public function power_cycle(Request $request): JsonResponse
    {
        return $this->runChassisCommand($request, 'cycle');
    }

    public function power_reset(Request $request): JsonResponse
    {
        return $this->runChassisCommand($request, 'reset');
    }

    public function soft_shutdown(Request $request): JsonResponse
    {
        return $this->runChassisCommand($request, 'soft');
    }

    private function generateId($name): string
    {
        $id = preg_replace("/[^A-Za-z0-9 _]/", '', $name);

        return strtolower(str_replace(' ', '_', $id));
    }

    private function runChassisCommand(Request $request, string $type):JsonResponse
    {
        $done = false;
        $cmd = $this->getCommand($request);

        if ($cmd !== false) {
            $ret = $this->runCommand(array_merge($cmd, ['chassis', 'power', $type]));

            if ($ret) {
                $done = true;
            }
        }

        return new JsonResponse([
            'success' => $done
        ]);
    }

    private function runCommand($command): bool|string
    {
        $proc = new Process($command);
        $proc->setTimeout(self::COMMAND_TIMEOUT);
        $proc->run();
        $output = $proc->getOutput();
        $exitCode = $proc->stop();

        if ($exitCode) {
            // let's log this error
            $message = "Error occurred when running \"" . implode(" ", $command) . "\".\n" . $proc->getErrorOutput();
            $this->debug[] = $message;
            error_log($message);
            return false;
        }

        return $output;
    }

    private function getCommand(Request $request): array|bool
    {
        $query = $request->query;
        $host = $query->get('host');

        if (empty($host)) {
            $message = 'No hostname provided!';
            $this->debug[] = $message;
            error_log($message);

            return false;
        }

        $ipmi = [
            'host' => $host,
            'port' => $query->get('port', self::DEFAULT_PORT),
            'user' => $query->get('user', self::DEFAULT_USERNAME),
            'password' => $query->get('password', ''),
            'interface' => $query->get('interface', self::DEFAULT_INTERFACE),
            'extra' => $query->get('extra', '')
        ];

        $cmd = ['ipmitool'];

        array_push($cmd, '-H', $ipmi['host'], '-p', $ipmi['port'], '-U', $ipmi['user'], '-P', $ipmi['password'], '-I', $ipmi['interface']);
        $cmd = array_merge($cmd, explode(' ', $ipmi['extra']));

        return $cmd;
    }

    private function getDeviceInfo(Request $request): array
    {
        $response = [
            'success' => false
        ];

        $device = [];
        $cmd = $this->getCommand($request);
        $found = false;
        $on = false;
        $error = 'Wrong connection data provided!';

        if ($cmd === false) {
            $response['message'] = $error;
        }
        else {
            try {
                $ret = $this->runCommand(array_merge($cmd, ['bmc', 'info']));

                if ($ret) {
                    $results = explode(PHP_EOL, $ret);
                    $device = $this->extractValuesFromResults($results);

                    $ret = $this->runCommand(array_merge($cmd, ['fru']));

                    if ($ret) {
                        $results = explode(PHP_EOL, $ret);
                        $device = array_merge($device, $this->extractValuesFromResults($results));
                    }

                    $ret = $this->runCommand(array_merge($cmd, ['chassis', 'power', 'status']));

                    if ($ret) {
                        $on = (trim($ret) === "Chassis Power is on");
                    }

                    $found = true;
                }

                if ($found) {
                    $response['success'] = true;
                    $response['device'] = $device;
                    $response['power_on'] = $on;
                } else {
                    $response['message'] = $error;
                }
            } catch (Exception $exception) {
                $response['message'] = $exception->getMessage();
            }
        }

        return $response;
    }

    private function extractValuesFromResults($results): array
    {
        $data = [];
        $results = array_values(array_filter($results, function ($line) {
            return str_contains($line, ':');
        }));

        if (!empty($results)) {
            foreach ($results as $result) {
                if (!empty($result)) {
                    $values = array_map('trim', explode(':', $result));
                    [$description, $value] = $values;

                    if (!empty($value)) {
                        $data[$this->generateId($description)] = $value;
                    }
                }
            }
        }

        return $data;
    }

    private function getSensors(Request $request): array
    {
        $response = [
            'success' => false
        ];

        $sensorData = [
        'temperature' => [],
        'voltage' => [],
        'fan' => [],
        'power' => [],
        'current' => []
    ];
        $states = [];
        $cmd = $this->getCommand($request);
        $found = false;

        if ($cmd !== false) {
            try {
                $ret = $this->runCommand(array_merge($cmd, ['sdr', 'list', 'full']));
                if ($ret) {
                    $results = explode(PHP_EOL, $ret);
                    if (!empty($results)) {
                        foreach ($results as $result) {
                            if (!empty($result)) {
                                $found = true;
                                $values = array_map('trim', explode('|', $result));
                                [$description, $value, $a] = $values;
    
                                foreach ($this->sensorTypes as $type => $unit) {
                                    if (str_contains($value, $unit)) {
                                        $value = trim(str_replace($unit, '', $value));
                                        $id = $this->generateId($description);
                                        $sensorData[$type][$id] = $description;
                                        $states[$id] = $value;
                                    }
                                }
                            }
                        }
                    }
                }

                if ($found) {
                    $response['success'] = true;
                    $response['sensors'] = $sensorData;
                    $response['states'] = $states;
                } else {
                    $response['message'] = 'Wrong connection data provided!';
                }
            } catch (Exception $exception) {
                $response['message'] = $exception->getMessage();
            }
        }

        return $response;
    }

}
