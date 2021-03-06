<?php

namespace Entity\Api;

use Entity;

/**
 * @SWG\Definition(type="object")
 */
class Station
{
    /**
     * Station ID
     * @SWG\Property(example=1)
     * @var int
     */
    public $id;

    /**
     * Station name
     * @SWG\Property(example="AzuraTest Radio")
     * @var string
     */
    public $name;

    /**
     * Station "short code", used for URL and folder paths
     * @SWG\Property(example="azuratest_radio")
     * @var string
     */
    public $shortcode;

    /**
     * Station description
     * @SWG\Property(example="An AzuraCast station!")
     * @var string
     */
    public $description;

    /**
     * Which broadcasting software (frontend) the station uses
     * @SWG\Property(example="shoutcast2")
     * @var string
     */
    public $frontend;

    /**
     * Which AutoDJ software (backend) the station uses
     * @SWG\Property(example="liquidsoap")
     * @var string
     */
    public $backend;

    /**
     * The full URL to listen to the default mount of the station
     * @SWG\Property(example="http://localhost:8000/radio.mp3")
     * @var string
     */
    public $listen_url;

    /**
     * @SWG\Property(type="array", @SWG\Items(ref="#/definitions/StationMount"))
     * @var array
     */
    public $mounts;
}