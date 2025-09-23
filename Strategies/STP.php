<?php

namespace Apps\Fintech\Packages\Etf\Strategies\Strategies;

use Apps\Fintech\Packages\Etf\Strategies\EtfStrategies;

class STP extends EtfStrategies
{
    public $strategyDisplayName = 'Systematic Transfer Plan (STP)';

    public $strategyDescription = 'Perform STP strategy on a portfolio';

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