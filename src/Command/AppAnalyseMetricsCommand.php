<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class AppAnalyseMetricsCommand.
 */
class AppAnalyseMetricsCommand extends Command
{
    const TEMPLATE = __DIR__.'/../../resources/templates/1.output';
    const TEMPLATE_2 = __DIR__.'/../../resources/templates/2.output';

    /**
     * @var string
     */
    protected static $defaultName = 'app:analyse-metrics';

    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        $this->setDescription('Analyses the metrics to generate a report.');
        $this->addOption('input', null, InputOption::VALUE_REQUIRED, 'The location of the test input');
    }

    /**
     * Detect slow-downs in the data and output them to stdout.
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     */
    protected function execute(InputInterface $input, OutputInterface $output): void
    {
        //Get the data
        //local file so normally no exceptions needed
        try {
            $file = file_get_contents($input->getOption('input'));
        } catch (Exception $e) {
            $output->writeln('Caught exception: ', $e->getMessage(), "\n");
        }
        $data = json_decode($file, true)['data'][0]['metricData'];
        //Get analysis for the data
        [
            $from, 
            $to, 
            $average, 
            $min, 
            $max, 
            $median,
            $underperformPeriods
        ] = $this->getAnalysis($data);
        //Insert analysis data in templates
        $template = file_get_contents(self::TEMPLATE);
        $template = str_replace(
            ['{{From}}', '{{To}}', '{{Average}}', '{{Min}}', '{{Max}}', '{{Median}}'],
            [$from, $to, $average, $min, $max, $median],
            $template
        );
        if (!empty($underperformPeriods)) {
            //Loop if there's multiple periods
            $template2 = file_get_contents(self::TEMPLATE_2);
            for ($i = 0; $i < count($underperformPeriods) / 2; ++$i) {
                $template .= str_replace(
                    ['{{From}}', '{{To}}'],
                    [$underperformPeriods[$i * 2], $underperformPeriods[$i * 2 + 1]],
                    $template2
                );
            }
        }
        $output->writeln($template);
    }

    /**
     * All the logic is here appart for the median in getMedian($data)
     * One loop to get the $from, $to, $average, $min, $max and $underperformPeriods variable to return $median comes later
     * The data has the dates ordered already it helps to find underperforming periods
     *
     * @param array $data
     * @return array
     */
    public function getAnalysis($data)
    {
        $from = $to = $previousTime = '';
        $average = $min = $max = $median = $sum = $previousValue = 0;
        $length = count($data);
        $underperformPeriods = [];
        foreach ($data as $index => list('dtime' => $dtime, 'metricValue' => $value)) {
            $value = $this->formatBytes($value); 
            $sum += $value;
            if (0 == $index) {
                $from = $dtime;
                $min = $value;
            } else {
                if ($previousValue > $value * 2) {
                    $underperformPeriods[] = $dtime;
                }
                if ($previousValue * 2 < $value) {
                    $underperformPeriods[] = $previousTime;
                }
            }
            if ($index == $length - 1) {
                $to = $dtime;
                $average = round(($sum / $length), 2);
            }
            if ($value > $max) {
                $max = $value;
            }
            if ($value < $min) {
                $min = $value;
            }
            $previousValue = $value;
            $previousTime = $dtime;
        }
        $median = $this->getMedian($data);

        return [$from, $to, $average, $min, $max, $median, $underperformPeriods];
    }

    /**
     * 
     * @param array $data
     * @return array
     */
    public function getMedian($data) : array
    {
        $length = count($data);
        usort($data, function ($a, $b) {
            return $a['metricValue'] > $b['metricValue'];
        });
        $index = (int) floor($length / 2);
        //Average on the 2 middle numbers if pair
        if ($length % 2 == 0) {
            $index = (int) floor($length / 2) - 1;
            return $this->formatBytes(($data[$index]['metricValue'] + $data[$index + 1]['metricValue']) / 2);
        }
        return $this->formatBytes($data[$index]['metricValue']);
    }

    /**
     * 
     * @param float $bytes
     * @param int $precision
     * @return float
     */
    public function formatBytes($bytes, $precision = 2): float
    {
        $bytes /= 125000;

        return round($bytes, $precision);
    }
}
