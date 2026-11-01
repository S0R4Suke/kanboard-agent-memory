<?php
namespace Kanboard\Plugin\MemoryHookFixture;

class Plugin extends \Kanboard\Core\Plugin\Base
{
    public function initialize()
    {
        $this->template->hook->attach('template:task:details:first-column', 'MemoryHookFixture:field');
        $this->template->hook->attach('template:task:details:bottom', 'MemoryHookFixture:widget');
    }
}
