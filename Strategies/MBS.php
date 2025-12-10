<?php

namespace Apps\Fintech\Packages\Etf\Strategies\Strategies;

use Apps\Fintech\Packages\Etf\Portfolios\EtfPortfolios;
use Apps\Fintech\Packages\Etf\Strategies\EtfStrategies;
use Apps\Fintech\Packages\Etf\Transactions\EtfTransactions;

class MBS extends EtfStrategies
{
    public $strategyDisplayName = 'Multiple Buy/Sell (MBS)';

    public $strategyDescription = 'Perform multiple buy/sell of any scheme or schemes';

    public $strategyArgs = [];

    public $transactions = [];

    public $transactionsCount = [];

    public $totalTransactionsCount = 0;

    protected $totalTransactionsAmounts = [];

    public function init()
    {
        $this->strategyArgs = $this->getStategyArgs();

        parent::init();

        return $this;
    }

    public function processStrategyTransactionsByDate($data, $date)
    {
        if (isset($this->transactions[$date]) && count($this->transactions[$date]) > 0) {
            foreach ($this->transactions[$date] as $transactionType => $transactions) {
                if ($transactionType === 'buy' &&
                    count($transactions) > 0
                ) {
                    foreach ($transactions as $transaction) {
                        $transaction['amc_transaction_id'] = '';
                        $transaction['portfolio_id'] = $data['portfolio_id'];
                        $transaction['details'] = 'Added via Strategy:' . $this->strategyDisplayName;
                        $transaction['via_strategies'] = true;
                        $transaction['strategy_id'] = (int) $data['strategy_id'];

                        $transactionPackage = $this->usePackage(EtfTransactions::class);
                        $transactionPackage->setFFValidation(false);

                        if (!$transactionPackage->addEtfTransaction($transaction)) {
                            $this->addResponse(
                                $transactionPackage->packagesData->responseMessage,
                                $transactionPackage->packagesData->responseCode,
                                $transactionPackage->packagesData->responseData ?? []
                            );

                            return false;
                        }
                    }
                }
            }

            foreach ($this->transactions[$date] as $transactionType => $transactions) {
                if ($transactionType === 'sell' &&
                    count($transactions) > 0
                ) {
                    foreach ($transactions as $transaction) {
                        $transaction['amc_transaction_id'] = '';
                        $transaction['portfolio_id'] = $data['portfolio_id'];
                        $transaction['details'] = 'Added via Strategy:' . $this->strategyDisplayName;
                        $transaction['via_strategies'] = true;
                        $transaction['strategy_id'] = (int) $data['strategy_id'];

                        $transactionPackage = $this->usePackage(EtfTransactions::class);
                        $transactionPackage->setFFValidation(false);

                        if (!$transactionPackage->addEtfTransaction($transaction)) {
                            if (str_contains($transactionPackage->packagesData->responseMessage, 'exceeds')) {
                                $transactions['sell_all'] = 'true';

                                if (!$transactionPackage->addEtfTransaction($transactions)) {
                                    $this->addResponse(
                                        $transactionPackage->packagesData->responseMessage,
                                        $transactionPackage->packagesData->responseCode,
                                        $transactionPackage->packagesData->responseData ?? []
                                    );

                                    return false;
                                }
                            }

                            $this->addResponse(
                                $transactionPackage->packagesData->responseMessage,
                                $transactionPackage->packagesData->responseCode,
                                $transactionPackage->packagesData->responseData ?? []
                            );

                            return false;
                        }
                    }
                }
            }

            return true;
        }

        $this->addResponse('Transaction with ' . $date . ' not found!', 1);

        return false;
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

        foreach ($data['data'] as $order) {
            if ($order['type'] === 'buy') {
                $this->transactionsCount['buy']++;

                if (!isset($this->transactions[$order['date']]['buy'])) {
                    $this->transactions[$order['date']]['buy'] = [];
                }
                $transaction = [];
                $transaction['type'] = 'buy';
                $transaction['scheme_id'] = $order['scheme_id'];
                $transaction['scheme'] = $order['scheme'];
                $transaction['date'] = $order['date'];
                $transaction['amount'] = (float) $order['amount'];
                $transaction['portfolio_id'] = $data['portfolio_id'];
                array_push($this->transactions[$order['date']]['buy'], $transaction);
                $this->totalTransactionsAmounts['buy'] += $transaction['amount'];
            } else if ($order['type'] === 'sell') {
                $this->transactionsCount['sell']++;

                if (!isset($this->transactions[$order['date']]['sell'])) {
                    $this->transactions[$order['date']]['sell'] = [];
                }
                $transaction = [];
                $transaction['type'] = 'sell';
                $transaction['scheme_id'] = $order['scheme_id'];
                $transaction['scheme'] = $order['scheme'];
                $transaction['date'] = $order['date'];
                $transaction['amount'] = (float) $order['amount'];
                $transaction['portfolio_id'] = $data['portfolio_id'];
                array_push($this->transactions[$order['date']]['sell'], $transaction);
                $this->totalTransactionsAmounts['sell'] += $transaction['amount'];
            }

            $this->totalTransactionsCount++;
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
                    'first_date'                    => $this->helper->firstKey($this->transactions),
                    'last_date'                     => $this->helper->lastKey($this->transactions),
                    'transactions'                  => $this->transactions
                ]
            );

            return $this->transactions;
        }

        $this->addResponse('Error calculating transactions, check data!', 1);
    }

    protected function checkData(&$data)
    {
        if (!isset($data['data']) ||
            isset($data['data']) && count($data['data']) === 0
        ) {
            $this->addResponse('Please provide multiple buy/sell data.', 1);

            return false;
        }

        $portfolioPackage = $this->usePackage(EtfPortfolios::class);

        $portfolio = $portfolioPackage->getPortfolioById($data['portfolio_id']);

        $checkPass = true;
        $sellAmounts = [];
        $thisPackage = $this;
        array_walk($data['data'], function($row, $index) use(&$data, $portfolio, &$checkPass, &$sellAmounts, &$thisPackage) {
            unset($data['data'][$index]['action']);

            if (!isset($row['type']) &&
                !isset($row['scheme_id']) &&
                !isset($row['date']) &&
                !isset($row['amount'])
            ) {
                $checkPass = false;

                $this->addResponse('Incomplete order data provided, please provide scheme_id, date, type and amount.', 1);

                return;
            }

            $row['type'] = strtolower($row['type']);
            $data['data'][$index]['type'] = strtolower($row['type']);

            if ($row['type'] === 'sell') {
                if (!isset($portfolio['investments']) ||
                    isset($portfolio['investments']) && count($portfolio['investments']) === 0
                ) {
                    $checkPass = false;

                    $this->addResponse('You have sell order in queue, but there are no investments in the portfolio. Please buy scheme first.', 1);

                    return;
                }

                if (!isset($portfolio['investments'][$row['scheme_id']])) {
                    $checkPass = false;

                    $this->addResponse('You have sell order in queue, but there are no investments in the portfolio. Please buy scheme first.', 1);

                    return;
                }

                if (!isset($sellAmounts[$row['scheme_id']])) {
                    $sellAmounts[$row['scheme_id']] = 0;
                }

                $sellAmounts[$row['scheme_id']] = $sellAmounts[$row['scheme_id']] + (float) $row['amount'];
            }
        });

        if (!$checkPass) {
            return false;
        }

        if (count($sellAmounts) > 0) {
            foreach ($sellAmounts as $sellAmountAmfiCode => $sellAmount) {
                if ($sellAmount > $portfolio['investments'][$sellAmountAmfiCode]['latest_value']) {
                    $this->addResponse('Sell order total is greater than available amount.', 1);

                    return false;
                }
            }
        }

        return true;
    }
}