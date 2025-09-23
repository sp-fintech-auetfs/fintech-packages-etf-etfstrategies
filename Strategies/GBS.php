<?php

namespace Apps\Fintech\Packages\Etf\Strategies\Strategies;

use Apps\Fintech\Packages\Etf\Strategies\EtfStrategies;

class GBS extends EtfStrategies
{
    public $strategyDisplayName = 'Gold Buy/Sell (GBS)';

    public $strategyDescription = 'Perform gold buy/sell strategy on a portfolio';

    public $strategyArgs = [];

    public function init()
    {
        $this->strategyArgs = $this->getStategyArgs();

        parent::init();

        return $this;
    }

    public function run($portfolio)
    {
        trace([$portfolio]);
    }

    protected function getStategyArgs()
    {
        return [];
    }
}