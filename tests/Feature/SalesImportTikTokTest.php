<?php

namespace Tests\Feature;

/** R-H-60 / IC-0.10: every test of SalesImportTest again on a TikTok-profile shop (the base class runs on an eBay-profile shop). */
class SalesImportTikTokTest extends SalesImportTest
{
    protected const MARKETPLACE = 'TikTok';
}
