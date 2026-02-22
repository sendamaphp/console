<?php

namespace Sendama\Console\Editor\States;

use Exception;
use Sendama\Console\Editor\IO\Enumerations\KeyCode;
use Sendama\Console\Editor\IO\Input;

class EditState extends EditorState
{

    /**
     * @inheritDoc
     * @throws Exception
     */
    public function update(): void
    {
        // TODO: Implement update() method.
        if (Input::isAnyKeyPressed([KeyCode::Q], true)) {
            $this->editor->stop();
        }
    }

    /**
     * @inheritDoc
     */
    public function render(): void
    {
        // TODO: Implement render() method.
    }
}