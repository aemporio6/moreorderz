<?php

namespace Tests\Feature;

/** R-H-60 / IC-0.10: every warehouse edge-case test of SalesDispatchTest again on a TikTok-profile shop (the base class runs on an eBay-profile shop). */
class SalesDispatchTikTokTest extends SalesDispatchTest
{
    protected const MARKETPLACE = 'TikTok';
}
