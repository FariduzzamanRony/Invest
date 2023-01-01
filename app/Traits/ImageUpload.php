<?php

namespace App\Traits;

use Str;

trait ImageUpload
{
    public function imageUploadTrait($query, $old = null): string // Taking input image as parameter
    {

        if ($old != null) {
            self::delete($old);
        }
        $image_name = Str::random(20);
        $ext = strtolower($query->getClientOriginalExtension()); // You can use also getClientOriginalName()
        $image_full_name = $image_name . '.' . $ext;
        $upload_path = 'assets/global/images/';    //Creating Sub directory in Assets folder to put image
        $image_url = $upload_path . $image_full_name;
        $success = $query->move($upload_path, $image_full_name);
        return str_replace('assets/','',$image_url); // Just return image
    }


    protected function delete($path)
    {
        if (file_exists('assets/'.$path)) {
            @unlink('assets/'.$path);
        }
    }
}
