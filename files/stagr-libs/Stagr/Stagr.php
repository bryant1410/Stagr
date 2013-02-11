<?php


/*
 * This file is part of Stagr.
 *
 */

namespace Stagr;

/**
 * Class Description [TODO]
 *
 * @author Gabriel Manricks <gmanricks@icloud.com>
 * @author Ulrich Kautz <ulrich.kautz@gmail.com>
 */

class Stagr extends \Cilex\Application
{
    const VERSION = '0.1.0';


    /**
     * Constructor for Stagr\Stagr
     *
     * @param \PHook  $ph  PHpook instance
     */
    public function __construct()
    {
        parent::__construct('Stagr', self::VERSION);
        $this->command(new \Cilex\Command\GreetCommand());
        $this->command(new \Stagr\Command\SetupCommand());
    }

}
