<?php

namespace Apps\Fintech\Packages\Etf\Strategies\Strategies;

use Apps\Fintech\Packages\Etf\Strategies\EtfStrategies;

class FDS extends EtfStrategies
{
    public $strategyDisplayName = 'Fixed Deposit Scheme (FDS)';

    public $strategyDescription = 'Perform fixed deposit scheme strategy on a portfolio';

    public $strategyArgs = [];

    public $transactions = [];

    public $transactionsCount = [];

    public $totalTransactionsCount = 0;

    protected $totalTransactionsAmounts = [];

    protected $startDate;

    protected $endDate;

    protected $startEndDates;

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
        }

        $this->checkData($data);

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
        $this->transactions[$date]['type'] = 'buy';
        $this->transactions[$date]['via_strategies'] = true;
        $this->transactions[$date]['date'] = $date;
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
        return [];
    }

    public function getStrategiesTransactions($data)
    {
        if (!$this->checkData($data)) {
            return false;
        }

        if (isset($data['calculations']) && $data['calculations'] == 'true') {
            return $this->getFdCalculations($data);
        }

        $currencySymbol = '$';

        $this->transactionsCount = ['buy' => 0, 'sell' => 0];
        $this->totalTransactionsAmounts = ['buy' => 0, 'sell' => 0];

        if (isset($data['scheme_id'])) {
            $this->totalTransactionsCount++;
            $this->transactionsCount['buy']++;
            $this->transactions[$data['investmentDate']]['type'] = 'buy';
            $this->transactions[$data['investmentDate']]['scheme'] = 'Fixed Deposit Scheme (FDS)';
            $this->transactions[$data['investmentDate']]['date'] = $data['investmentDate'];
            $this->transactions[$data['investmentDate']]['amount'] = (float) $data['investmentAmount'];
            $this->totalTransactionsAmounts['buy'] += $this->transactions[$data['investmentDate']]['amount'];
        }

        foreach ($this->startEndDates as $index => $date) {
            $dateString = $date->toDateString();

            if ($dateString === $data['investmentDate']) {//No need to overwrite first order.
                continue;
            }

            if ($data['schedule'] === 'weekly') {
                if (in_array($date->dayOfWeek(), $data['weekly_days'])) {
                    $this->totalTransactionsCount++;
                    $this->transactionsCount['buy']++;
                    $this->transactions[$dateString]['type'] = 'buy';
                    $this->transactions[$dateString]['scheme'] = $data['scheme']['name'];
                    $this->transactions[$dateString]['date'] = $dateString;

                    if ((isset($data['scheme_id']) && count($this->transactions) === 2) ||
                        (!isset($data['scheme_id']) && count($this->transactions) === 1)
                    ) {
                        $this->incrementWeek = $date->weekOfYear;
                        $this->incrementMonth = $date->month;
                        $this->incrementYear = $date->year;

                        $this->transactions[$dateString]['amount'] = (float) $data['amount'];
                    } else {
                        if ($this->incrementSchedule !== 'increment-none') {
                            $this->transactions[$dateString]['amount'] = $this->getIncrementedAmount($date, $data);
                        } else {
                            $this->transactions[$dateString]['amount'] = (float) $data['amount'];
                        }
                    }

                    $this->totalTransactionsAmounts['buy'] += $this->transactions[$dateString]['amount'];

                    $this->trajectoryMaxInvestAmount = 0;
                    $this->trajectoryInvestAmount = 0;
                }
            } else if ($data['schedule'] === 'monthly') {
                if (in_array($date->month, $data['monthly_months'])) {
                    if ($date->day == $data['monthly_day']) {
                        //If transaction is happening on Sunday, move it to Monday.
                        if ($date->englishDayOfWeek === 'Sunday') {
                            $date = $date->addDay();
                            $dateString = $date->toDateString();
                        } else if ($date->englishDayOfWeek === 'Saturday') {
                            $date = $date->addDay(2);
                            $dateString = $date->toDateString();
                        }

                        if (!isset($this->transactions[$dateString])) {
                            $this->transactions[$dateString] = [];
                        }

                        $this->totalTransactionsCount++;
                        $this->transactionsCount['buy']++;
                        $this->transactions[$dateString]['type'] = 'buy';
                        $this->transactions[$dateString]['scheme'] = $data['scheme']['name'];
                        $this->transactions[$dateString]['date'] = $dateString;

                        if ((isset($data['scheme_id']) && count($this->transactions) === 2) ||
                            (!isset($data['scheme_id']) && count($this->transactions) === 1)
                        ) {
                            $this->incrementWeek = $date->weekOfYear;
                            $this->incrementMonth = $date->month;
                            $this->incrementYear = $date->year;

                            $this->transactions[$dateString]['amount'] = (float) $data['amount'];
                        } else {
                            if ($this->incrementSchedule !== 'increment-none') {
                                $this->transactions[$dateString]['amount'] = $this->getIncrementedAmount($date, $data);
                            } else {
                                $this->transactions[$dateString]['amount'] = (float) $data['amount'];
                            }
                        }

                        $this->totalTransactionsAmounts['buy'] += $this->transactions[$dateString]['amount'];

                        $this->trajectoryMaxInvestAmount = 0;
                        $this->trajectoryInvestAmount = 0;
                    }
                }
            }

            if (isset($data['trajectory']) && $data['trajectory'] !== 'no') {
                if (!isset($this->scheme['navs']['navs'][$dateString])) {
                    $this->addResponse('Nav for date:' . $dateString . ' of the selected scheme not present, Please import navs.', 1);

                    return false;
                }

                if ($this->scheme['navs']['navs'][$dateString]['trajectory'] !== $data['trajectory']) {//Check if trajectory is same.
                    continue;
                }

                if ($data['trajectory_percent'] !== 0) {
                    if (isset($this->scheme['navs']['navs'][$dateString]['diff_percent'])) {
                        if (abs($this->scheme['navs']['navs'][$dateString]['diff_percent']) < $data['trajectory_percent']) {
                            continue;
                        }
                    }
                }

                if ($this->trajectoryMaxInvestAmount === 0) {
                    $this->trajectoryMaxInvestAmount = (float) $data['trajectory_max_invest_amount'];
                }

                if ($this->trajectoryInvestAmount >= $this->trajectoryMaxInvestAmount) {//Max Transactions reached.
                    continue;
                }

                $trajectoryDate = \Carbon\Carbon::parse($dateString);
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

                if ((int) $data['consecutive_days'] >= 0) {
                    if ((int) $data['consecutive_days'] > 0) {
                        if ($this->monitoringDays < (int) $data['consecutive_days']) {
                            $this->monitoringDays++;

                            continue;
                        }

                        $this->trajectoryInvestAmount = (float) $this->trajectoryInvestAmount + $data['trajectory_invest_amount'];

                    }

                    if (!isset($this->transactions[$trajectoryDateString])) {
                        $this->transactions[$trajectoryDateString] = [];
                        $this->transactions[$trajectoryDateString]['date'] = $trajectoryDateString;
                        $this->transactions[$trajectoryDateString]['scheme'] = $data['scheme']['name'];
                        $this->transactions[$trajectoryDateString]['type'] = 'buy';
                        $this->transactions[$trajectoryDateString]['amount'] = (float) $data['trajectory_invest_amount'];
                        $this->monitoringDays = 0;
                    }
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

    protected function getFdCalculations($data)
    {
        $responseData = [];
        $responseData['duration'] = $this->startDate->diff($this->endDate)->format('%yY %mM %dD');
        $responseData['total_interest'] = 0;
        $responseData['financial_years_interest'] = [];

        $diffInYears = $this->startDate->diffInYears($this->endDate);
        $diffInMonths = $this->startDate->diffInMonths($this->endDate);
        $diffInDays = $this->startDate->diffInDays($this->endDate);

        if ($this->startDate->month <= 3) {
            $responseData['financial_years_interest'][$this->startDate->copy()->subYear()->year . '-' . $this->startDate->year] = 0;

            $financialYear = &$responseData['financial_years_interest'][$this->startDate->copy()->subYear()->year . '-' . $this->startDate->year];
        } else {
            $responseData['financial_years_interest'][$this->startDate->year . '-' . $this->startDate->copy()->addYear()->year] = 0;

            $financialYear = &$responseData['financial_years_interest'][$this->startDate->year . '-' . $this->startDate->copy()->addYear()->year];
        }

        // if ($diffInQuarters > 1) {
        //     for ($quarters = 1; $quarters <= floor($diffInQuarters); $quarters++) {
        //         $nextQuarter = $this->startDate->copy()->addQuarter($quarters);

        //         array_push($responseData['quarters'], $nextQuarter->toDateString());
        //     }
        // }
        // trace([$responseData]);

        if ($diffInYears > 0.5) {//Recalculate Interest rate as per number of years.
            $data['interestRate'] = (float) ($data['interestRate'] * $diffInYears) / 100;

            for ($years = 1; $years < round($diffInYears); $years++) {
                $nextYear = $this->startDate->copy()->addYears($years);

                $responseData['financial_years_interest'][$nextYear->year . '-' . $nextYear->addYear()->year] = 0;
            }

            //Get Per day Interest Amount
            $principalReturnAsPerInterest = $data['amount'] * $data['interestRate'];
            $perDayInterest = round($principalReturnAsPerInterest / $diffInDays, 3);

            if ($diffInMonths > 0) {
                //FirstMonth
                $responseData['rows'][$this->startDate->toDateString()]['date'] = $this->startDate->toDateString();
                $responseData['rows'][$this->startDate->toDateString()]['interest_amount'] = 0;
                $responseData['rows'][$this->startDate->toDateString()]['interest_capitalized'] = null;
                $responseData['rows'][$this->startDate->toDateString()]['fd_balance'] = $data['amount'];

                $responseData['rows'][$this->startDate->copy()->endOfMonth()->toDateString()]['date'] = $this->startDate->copy()->endOfMonth()->toDateString();
                $firstMonthDiffInDays = $this->startDate->diffInDays($this->startDate->copy()->endOfMonth());
                $responseData['rows'][$this->startDate->copy()->endOfMonth()->toDateString()]['interest_amount'] = round($firstMonthDiffInDays * $perDayInterest, 2);
                $responseData['rows'][$this->startDate->copy()->endOfMonth()->toDateString()]['interest_capitalized'] = null;
                $responseData['rows'][$this->startDate->copy()->endOfMonth()->toDateString()]['fd_balance'] = $data['amount'];

                // Additional Months
                if ($diffInMonths > 1) {
                    for ($months = 1; $months < floor($diffInMonths); $months++) {
                        $month = $this->startDate->copy()->addMonths($months);

                        if ($month->month <= 3) {
                            if (!isset($responseData['financial_years_interest'][$month->copy()->subYear()->year . '-' . $month->year])) {
                                $responseData['financial_years_interest'][$month->copy()->subYear()->year . '-' . $month->year] = 0;
                            }

                            $financialYear = &$responseData['financial_years_interest'][$month->copy()->subYear()->year . '-' . $month->year];
                        } else {
                            if (!isset($responseData['financial_years_interest'][$month->year . '-' . $month->copy()->addYear()->year])) {
                                $responseData['financial_years_interest'][$month->year . '-' . $month->copy()->addYear()->year] = 0;
                            }

                            $financialYear = &$responseData['financial_years_interest'][$month->year . '-' . $month->copy()->addYear()->year];
                        }

                        if ($this->startDate->day !== 1) {
                            $startOfMonth = $month->copy()->startOfMonth()->addDays($this->startDate->day - 1)->toDateString();
                        } else {
                            $startOfMonth = $month->startOfMonth()->toDateString();
                        }

                        $responseData['rows'][$startOfMonth]['date'] = $startOfMonth;

                        if ($this->startDate->day !== 1) {
                            $responseData['rows'][$startOfMonth]['interest_amount'] = round(($this->startDate->day - 1) * $perDayInterest, 2);

                            $financialYear += $responseData['rows'][$startOfMonth]['interest_capitalized'] =
                                round($responseData['rows'][$startOfMonth]['interest_amount'] +
                                      $responseData['rows'][$month->copy()->subMonth()->endOfMonth()->toDateString()]['interest_amount']
                                );
                        } else {
                            $responseData['rows'][$startOfMonth]['interest_amount'] = 0;
                        }

                        $responseData['rows'][$startOfMonth]['fd_balance'] = $data['amount'];

                        $responseData['rows'][$month->copy()->endOfMonth()->toDateString()]['date'] = $month->copy()->endOfMonth()->toDateString();
                        $monthDiffInDays = $month->diffInDays($month->copy()->endOfMonth());
                        $financialYear +=
                            $responseData['rows'][$month->copy()->endOfMonth()->toDateString()]['interest_amount'] =
                                round($monthDiffInDays * $perDayInterest, 2);
                        $responseData['rows'][$month->copy()->endOfMonth()->toDateString()]['interest_capitalized'] = null;
                        $responseData['rows'][$month->copy()->endOfMonth()->toDateString()]['fd_balance'] = $data['amount'];
                        var_dump($responseData['financial_years_interest']);
                    }

                    if (floor($diffInMonths) != $diffInMonths) {
                        //We have additional days till Maturity
                        //LastMonth (if additional days)

                        if ($this->endDate->copy()->month <= 3) {
                            $financialYear = &$responseData['financial_years_interest'][$this->endDate->copy()->subYear()->year . '-' . $this->endDate->copy()->year];
                        } else {
                            $financialYear = &$responseData['financial_years_interest'][$this->endDate->copy()->year . '-' . $this->endDate->copy()->addYear()->year];
                        }

                        if ($this->startDate->day !== 1) {
                            $startOfMonth = $this->endDate->copy()->startOfMonth()->addDays($this->startDate->day - 1)->toDateString();
                        } else {
                            $startOfMonth = $this->endDate->copy()->startOfMonth()->toDateString();
                        }

                        $responseData['rows'][$startOfMonth]['date'] = $startOfMonth;

                        if ($this->startDate->day !== 1) {
                            $responseData['rows'][$startOfMonth]['interest_amount'] = round(($this->startDate->day - 1) * $perDayInterest, 2);

                            $responseData['rows'][$startOfMonth]['interest_capitalized'] =
                                round($responseData['rows'][$startOfMonth]['interest_amount'] +
                                      $responseData['rows'][$this->endDate->copy()->subMonth()->endOfMonth()->toDateString()]['interest_amount']
                                );
                        } else {
                            $responseData['rows'][$startOfMonth]['interest_amount'] = 0;
                        }

                        $responseData['rows'][$startOfMonth]['fd_balance'] = $data['amount'];

                        $responseData['rows'][$this->endDate->toDateString()]['date'] = $this->endDate->toDateString();
                        if ($this->startDate->day !== 1) {
                            $lastMonthDiffInDays = $this->endDate->copy()->startOfMonth()->addDays($this->startDate->day - 1)->diffInDays($this->endDate);
                        } else {
                            $lastMonthDiffInDays = $this->endDate->copy()->startOfMonth()->diffInDays($this->endDate);
                        }
                        $financialYear +=
                            $responseData['rows'][$this->endDate->toDateString()]['interest_amount'] =
                                round($lastMonthDiffInDays * $perDayInterest, 2);
                        $responseData['rows'][$this->endDate->toDateString()]['interest_capitalized'] = round($lastMonthDiffInDays * $perDayInterest);
                        $responseData['rows'][$this->endDate->toDateString()]['fd_balance'] = $data['amount'];
                    }
                }
            }

            foreach ($responseData['financial_years_interest'] as &$fYInterest) {
                $fYInterest = round($fYInterest, 2);

                $responseData['total_interest'] += $fYInterest;
            }

            $responseData['total_interest'] = round($responseData['total_interest']);
                    trace([$responseData]);
        } else {
            //Less than 6 months uses simple interest formula
            // Formula: M = P + (P x R x T/100)
            // M: Maturity amount (total amount at the end)
            // P: Principal amount (the initial deposit)
            // R: Annual interest rate (e.g., 6%)
            // T: Tenure (time period) in years
            $maturityAmount = round($data['amount'] * $data['interestRate'] * ($diffInYears / 100), 3);

            $perDayInterest = $maturityAmount / $diffInDays;
            // trace([$diffInDays, $maturityAmount, $perDayInterest]);
            if ($diffInMonths > 0) {
                //FirstMonth
                $responseData['rows'][$this->startDate->toDateString()]['date'] = $this->startDate->toDateString();
                $responseData['rows'][$this->startDate->toDateString()]['interest_amount'] = 0;
                $responseData['rows'][$this->startDate->toDateString()]['interest_capitalized'] = null;
                $responseData['rows'][$this->startDate->toDateString()]['fd_balance'] = $data['amount'];

                $responseData['rows'][$this->startDate->copy()->endOfMonth()->toDateString()]['date'] = $this->startDate->copy()->endOfMonth()->toDateString();
                $firstMonthDiffInDays = round($this->startDate->diffInDays($this->startDate->copy()->endOfMonth()));
                $financialYear +=
                    $responseData['rows'][$this->startDate->copy()->endOfMonth()->toDateString()]['interest_amount'] =
                        round($firstMonthDiffInDays * $perDayInterest, 2);
                $responseData['rows'][$this->startDate->copy()->endOfMonth()->toDateString()]['interest_capitalized'] = null;
                $responseData['rows'][$this->startDate->copy()->endOfMonth()->toDateString()]['fd_balance'] = $data['amount'];

                // Additional Months
                if ($diffInMonths > 1) {
                    for ($months = 1; $months < floor($diffInMonths); $months++) {
                        $month = $this->startDate->copy()->addMonths($months);

                        if ($month->month <= 3) {
                            $financialYear = &$responseData['financial_years_interest'][$month->copy()->subYear()->year . '-' . $month->year];
                        } else {
                            $financialYear = &$responseData['financial_years_interest'][$month->year . '-' . $month->copy()->addYear()->year];
                        }

                        $responseData['rows'][$month->copy()->endOfMonth()->toDateString()]['date'] = $month->copy()->endOfMonth()->toDateString();
                        $monthDiffInDays = round($month->diffInDays($month->copy()->endOfMonth()));

                        $financialYear +=
                            $responseData['rows'][$month->copy()->endOfMonth()->toDateString()]['interest_amount'] =
                                round($monthDiffInDays * $perDayInterest, 2);
                        $responseData['rows'][$month->copy()->endOfMonth()->toDateString()]['interest_capitalized'] = null;
                        $responseData['rows'][$month->copy()->endOfMonth()->toDateString()]['fd_balance'] = $data['amount'];
                    }

                            // trace([$responseData['financial_years_interest']]);
                    if (floor($diffInMonths) != $diffInMonths) {
                        //We have additional days till Maturity
                        //LastMonth (if additional days)
                        if ($this->endDate->copy()->month <= 3) {
                            $financialYear = &$responseData['financial_years_interest'][$this->endDate->copy()->subYear()->year . '-' . $this->endDate->copy()->year];
                        } else {
                            $financialYear = &$responseData['financial_years_interest'][$this->endDate->copy()->year . '-' . $this->endDate->copy()->addYear()->year];
                        }

                        $responseData['rows'][$this->endDate->toDateString()]['date'] = $this->endDate->toDateString();
                        $lastMonthDiffInDays = round($this->endDate->copy()->startOfMonth()->diffInDays($this->endDate));
                        $financialYear +=
                            $responseData['rows'][$this->endDate->toDateString()]['interest_amount'] =
                                round($lastMonthDiffInDays * $perDayInterest, 2);
                        $responseData['rows'][$this->endDate->toDateString()]['interest_capitalized'] = 0;
                        $responseData['rows'][$this->endDate->toDateString()]['fd_balance'] = $data['amount'];
                    }
                }
            }

            foreach ($responseData['financial_years_interest'] as $fYInterest) {
                $responseData['total_interest'] += $fYInterest;
            }

            $responseData['total_interest'] = round($responseData['total_interest']);

            $responseData['rows'][$this->helper->lastKey($responseData['rows'])]['interest_capitalized'] = $responseData['total_interest'];
            $responseData['rows'][$this->helper->lastKey($responseData['rows'])]['fd_balance'] = $data['amount'] + $responseData['total_interest'];
        }

        trace([$responseData, $perDayInterest, $data]);
        // trace([$responseData, $data, $perDayInterest, $principalReturnAsPerInterest]);

        $currencySymbol = '$';

        if (count($this->transactions) > 0) {
            $this->addResponse(
                'Calculated FD',
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

        $this->addResponse('Error calculating transactions, contact developer!', 1);
    }

    protected function checkData(&$data)
    {
        if (isset($data['calculations']) && $data['calculations'] == 'true') {
            try {
                $this->startDate = \Carbon\Carbon::parse($data['startDate']);
                $this->endDate = \Carbon\Carbon::parse($data['endDate']);
            } catch (\throwable $e) {
                $this->addResponse('Dates provided are incorrect', 1);

                return false;
            }
        } else {
            try {
                $this->startEndDates = (\Carbon\CarbonPeriod::between($data['startDate'], $data['endDate']))->toArray();
            } catch (\throwable $e) {
                $this->addResponse('Dates provided are incorrect', 1);

                return false;
            }
        }

        if (!isset($data['amount'])) {
            $this->addResponse('Please provide amount', 1);

            return false;
        }

        $data['amount'] = (float) $data['amount'];
        $data['interestRate'] = (float) $data['interestRate'];

        if (!isset($data['interestRate'])) {
            $this->addResponse('Please provide interest rate', 1);

            return false;
        }

        if (!isset($data['interestPayout']) ||
            (isset($data['interestPayout']) && $data['interestPayout'] === '')
        ) {
            $this->addResponse('Please provide interest payout', 1);

            return false;
        }

        if (!isset($data['compounding']) ||
            (isset($data['compounding']) && $data['compounding'] === '')
        ) {
            $data['compounding'] = 'quarterly';
        }

        return true;
    }
}