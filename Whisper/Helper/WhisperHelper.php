<?php
namespace Kanboard\Plugin\Whisper\Helper;
class WhisperHelper extends \Kanboard\Core\Base
{
    public function allowed($id) { return \Kanboard\Plugin\Whisper\Service\Access::allowed($this->container, $id); }
}
