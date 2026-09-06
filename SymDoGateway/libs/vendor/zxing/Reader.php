<?php

namespace da8ter\SymDo\Zxing;

interface Reader
{
	public function decode(BinaryBitmap $image);

	public function reset();
}
