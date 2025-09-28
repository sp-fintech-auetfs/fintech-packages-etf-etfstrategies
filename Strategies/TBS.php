<?php

namespace Apps\Fintech\Packages\Etf\Strategies\Strategies;

use Apps\Fintech\Packages\Etf\Strategies\EtfStrategies;
use Apps\Fintech\Packages\Etf\Transactions\EtfTransactions;

class TBS extends EtfStrategies
{
    public $strategyDisplayName = 'Trajectory Buy/Sell (TBS)';

    public $strategyDescription = 'Perform trajectory based buying/selling strategy on a portfolio';

    public $strategyArgs = [];

    public $transactions = [];

    public $transactionsCount = [];

    protected $totalTransactionsAmounts = [];

    protected $transactionPackage;

    protected $startEndDates;

    protected $weekly = 0;

    protected $monthly = 0;

    protected $carryForwardAmount = 0;

    protected $trajectoryMaxInvestAmount = 0;

    protected $trajectoryInvestAmount = 0;

    protected $monitoringDays = 0;

    protected $scheme;

    protected $schemeNavs = [];

    public function init()
    {
        $this->strategyArgs = $this->getStategyArgs();

        parent::init();

        return $this;
    }

    public function processStrategyTransactionsByDate($data, $date)
    {
        if (!$this->transactionPackage) {
            $this->transactionPackage = $this->usePackage(EtfTransactions::class);

            $this->transactionPackage->setFFValidation(false);
        }

        if (isset($this->transactions[$date])) {
            return $this->generateTransaction($data, $date);
        }

        $this->addResponse('Transaction with ' . $date . ' not found!', 1);

        return false;
    }

    protected function generateTransaction($data, $date)
    {
        $this->transactions[$date]['portfolio_id'] = (int) $data['portfolio_id'];
        $this->transactions[$date]['amc_id'] = (int) $data['amc_id'];
        if (isset($data['scheme_id'])) {
            $this->transactions[$date]['scheme_id'] = (int) $data['scheme_id'];
        } else if (isset($data['amfi_code'])) {
            $this->transactions[$date]['amfi_code'] = (int) $data['amfi_code'];
        }
        $this->transactions[$date]['amc_transaction_id'] = '';
        $this->transactions[$date]['details'] = 'Added via Strategy:' . $this->strategyDisplayName;
        $this->transactions[$date]['via_strategies'] = true;
        $this->transactions[$date]['strategy_id'] = (int) $data['strategy_id'];

        if (!$this->transactionPackage->addEtfTransaction($this->transactions[$date])) {
            $this->addResponse(
                $this->transactionPackage->packagesData->responseMessage,
                $this->transactionPackage->packagesData->responseCode,
                $this->transactionPackage->packagesData->responseData ?? []
            );

            return false;
        }

        return true;
    }

    protected function getStategyArgs()
    {
        return [
        ];
    }

    public function getStrategiesTransactions($data)
    {
        if (!$this->checkData($data)) {
            return false;
        }

        $currencySymbol = '$';

        $this->transactionsCount = ['buy' => 0, 'sell' => 0];
        $this->totalTransactionsAmounts = ['buy' => 0, 'sell' => 0];

        if (!$this->scheme) {
            $this->scheme = $this->getSchemeFromAmfiCodeOrSchemeId($data, true);

            if (!isset($this->scheme['navs']['navs'])) {
                $this->addResponse('Navs of the selected scheme not present, Please import navs.', 1);

                return false;
            }
        }

        foreach ($this->startEndDates as $dateIndex => $date) {
            $dateString = $date->toDateString();

            if (!isset($this->scheme['navs']['navs'][$dateString])) {
                $this->addResponse('Nav for date:' . $dateString . ' of the selected scheme not present, Please import navs.', 1);

                return false;
            }

            if ($this->scheme['navs']['navs'][$dateString]['trajectory'] !== $data['trajectory']) {//Check if trajectory is same.
                continue;
            }

            if ($data['trajectory_percent'] !== 0) {
                if (isset($this->scheme['navs']['navs'][$dateString]['diff_percent']) &&
                    abs($this->scheme['navs']['navs'][$dateString]['diff_percent']) < $data['trajectory_percent']
                ) {
                    continue;
                }
            }

            $trajectoryDate = \Carbon\Carbon::parse($dateString);

            if ($data['schedule'] === 'weekly') {
                ${$data['schedule']} = $trajectoryDate->weekOfYear;
            } else if ($data['schedule'] === 'monthly') {
                ${$data['schedule']} = $trajectoryDate->month;
            }

            if ($this->{$data['schedule']} === 0) {//First entry
                $this->{$data['schedule']} = ${$data['schedule']};
            }

            if ($this->trajectoryInvestAmount >= $this->trajectoryMaxInvestAmount && $this->trajectoryMaxInvestAmount !== 0) {//Max Transactions reached.
                if ($this->{$data['schedule']} == ${$data['schedule']}) {
                    continue;
                } else {
                    $this->trajectoryMaxInvestAmount = 0;
                    $this->trajectoryInvestAmount = 0;
                }
            }

            if ($this->trajectoryMaxInvestAmount === 0) {
                $this->trajectoryMaxInvestAmount = (float) $data['trajectory_max_invest_amount'];
            }

            //If transaction is happening on Sunday, move it to Monday.
            if ($trajectoryDate->englishDayOfWeek === 'Sunday') {
                $trajectoryDate = $trajectoryDate->addDay();
                $trajectoryDateString = $trajectoryDate->toDateString();
            } else if ($trajectoryDate->englishDayOfWeek === 'Saturday') {
                $trajectoryDate = $trajectoryDate->addDay(2);
                $trajectoryDateString = $trajectoryDate->toDateString();
            } else {
                $trajectoryDateString = $dateString;
            }

            if ($trajectoryDate->gt(\Carbon\Carbon::parse($data['endDate']))) {
                continue;
            }

            if ((int) $data['consecutive_days'] > 0) {
                if ($this->monitoringDays < (int) $data['consecutive_days']) {
                    $this->monitoringDays++;

                    continue;
                }
            }

            if (${$data['schedule']} > $this->{$data['schedule']}) {//Change of schedule.
                $this->{$data['schedule']} = ${$data['schedule']};

                if (isset($data['carry_forward_to_next_schedule']) &&
                    $data['carry_forward_to_next_schedule'] == 'true'
                ) {
                    if ($this->trajectoryInvestAmount != 0 &&
                        $this->trajectoryInvestAmount < $this->trajectoryMaxInvestAmount
                    ) {//Carry Forward
                        $this->trajectoryMaxInvestAmount = $data['trajectory_max_invest_amount'] + ($data['trajectory_max_invest_amount'] - $this->trajectoryInvestAmount);
                    }

                    $this->trajectoryInvestAmount = 0;
                }

                $this->addTransaction($trajectoryDateString, $data);
            } else {//Same schedule period
                if (isset($this->scheme['navs']['navs'][$dateString]['trajectory']) &&
                    $this->scheme['navs']['navs'][$dateString]['trajectory'] === $data['trajectory']
                ) {
                    if ($data['trajectory_percent'] !== 0) {
                        if (isset($this->scheme['navs']['navs'][$dateString]['diff_percent']) &&
                            abs($this->scheme['navs']['navs'][$dateString]['diff_percent']) < $data['trajectory_percent']
                        ) {
                            continue;
                        }
                    }

                    if ((int) $data['consecutive_days'] > 0) {
                        if ($this->monitoringDays === (int) $data['consecutive_days']) {//Create Transaction
                            $this->addTransaction($trajectoryDateString, $data);
                        } else if ($this->monitoringDays < (int) $data['consecutive_days']) {
                            $this->monitoringDays++;

                            continue;
                        }
                    } else if ((int) $data['consecutive_days'] === 0) {//Create Transaction
                        $this->addTransaction($trajectoryDateString, $data);
                    }
                } else {
                    $this->monitoringDays = 0;//Reset monitoring days.
                }
            }
        }

        if (count($this->transactions) > 0) {
            $this->addResponse(
                'Calculated Transactions',
                0,
                [
                    'total_transactions_count'      => $this->transactionsCount,
                    'total_transactions_amount'     => 'Buy: ' . $currencySymbol .
                        str_replace('EN_ ',
                            '',
                            (new \NumberFormatter('en_IN', \NumberFormatter::CURRENCY))
                                ->formatCurrency($this->totalTransactionsAmounts['buy'], 'en_IN')) .
                        ' Sell: ' . $currencySymbol .
                        str_replace('EN_ ',
                            '',
                            (new \NumberFormatter('en_IN', \NumberFormatter::CURRENCY))
                                ->formatCurrency($this->totalTransactionsAmounts['sell'], 'en_IN'))
                    ,
                    'first_date'                    => $this->helper->first($this->transactions)['date'],
                    'last_date'                     => $this->helper->last($this->transactions)['date'],
                    'transactions'                  => $this->transactions
                ]
            );

            return $this->transactions;
        }

        $this->addResponse('Error calculating transactions, check dates!', 1);
    }

    protected function addTransaction($trajectoryDateString, $data)
    {
        if (!isset($this->transactions[$trajectoryDateString])) {
            $this->transactions[$trajectoryDateString] = [];
            $this->transactions[$trajectoryDateString]['date'] = $trajectoryDateString;
            $this->transactions[$trajectoryDateString]['scheme'] = $this->scheme['name'];
            $this->transactions[$trajectoryDateString]['type'] = $data['transaction_type'];

            $this->transactions[$trajectoryDateString]['amount'] = (float) $data['trajectory_invest_amount'];
            $this->trajectoryInvestAmount += $this->transactions[$trajectoryDateString]['amount'];
            $this->totalTransactionsAmounts[$data['transaction_type']] += $this->transactions[$trajectoryDateString]['amount'];

            $this->transactionsCount[$data['transaction_type']]++;

            $this->monitoringDays = 0;
        }
    }

    protected function checkData(&$data)
    {
        try {
            $this->startEndDates = (\Carbon\CarbonPeriod::between($data['startDate'], $data['endDate']))->toArray();
        } catch (\throwable $e) {
            $this->addResponse('Dates provided are incorrect', 1);

            return false;
        }

        if (!isset($data['amfi_code']) && !isset($data['scheme_id'])) {
            $this->addResponse('Investment scheme not provided', 1);

            return false;
        }

        $this->scheme = $this->getSchemeFromAmfiCodeOrSchemeId($data, true);

        if (!$this->scheme) {
            $this->addResponse('Please provide correct scheme amfi code or scheme id', 1);

            return false;
        }

        if (!isset($this->scheme['navs']['navs'])) {
            $this->addResponse('Navs of the selected scheme not present, Please import navs.', 1);

            return false;
        }

        if (!isset($data['consecutive_days']) ||
            isset($data['consecutive_days']) && $data['consecutive_days'] == ''
        ) {
            $data['consecutive_days'] = 0;
        }

        $data['consecutive_days'] = (int) $data['consecutive_days'];

        if ($data['consecutive_days'] > 4) {
            $data['consecutive_days'] = 4;
        }

        if (!isset($data['trajectory_percent']) ||
            isset($data['trajectory_percent']) && $data['trajectory_percent'] == ''
        ) {
            $data['trajectory_percent'] = 0;
        }

        $data['trajectory_percent'] = (float) abs((int) $data['trajectory_percent']);

        $data['trajectory_max_invest_amount'] = (float) $data['trajectory_max_invest_amount'];
        if ($data['trajectory_max_invest_amount'] == 0) {
            $this->addResponse('Please provide Trajectory max investment amount', 1);

            return false;
        }

        $data['trajectory_invest_amount'] = (float) $data['trajectory_invest_amount'];
        if ($data['trajectory_invest_amount'] == 0) {
            $this->addResponse('Please provide Trajectory investment amount', 1);

            return false;
        }

        if (!isset($data['schedule'])) {
            $this->addResponse('Please provide schedule', 1);

            return false;
        }

        if ($data['schedule'] === 'monthly' &&
            (!isset($data['monthly_months']) || !isset($data['monthly_day']))
        ) {
            $this->addResponse('Please provide schedule months data', 1);

            return false;
        }

        if ($data['schedule'] === 'weekly' && !isset($data['weekly_days'])) {
            $this->addResponse('Please provide schedule weeks', 1);

            return false;
        }

        return true;
    }
}