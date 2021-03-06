<?php

namespace Ie\FileManager\Http\Controllers;

use Ie\FileManager\Http\Requests\CreateNewRequest;
use Ie\FileManager\Http\Requests\RenameRequest;
use Ie\FileManager\App\Services\Storage\FileStructure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Pion\Laravel\ChunkUpload\Exceptions\UploadMissingFileException;
use Pion\Laravel\ChunkUpload\Handler\HandlerFactory;
use Pion\Laravel\ChunkUpload\Receiver\FileReceiver;

class UploadingController extends Controller
{

    private $fileSystem;

    public function __construct(FileStructure $fileSystem)
    {
        $this->fileSystem=$fileSystem;
    }



    public function uploadFilesByChunks(Request  $request)
    {
     return  $this->fileSystem->uploadLargeFiles($request);
    }


    public function renameFileView(Request  $request)
    {
        $oldName=$request->input('old_name');
        $path=$request->input('path');
        $type=$request->input('type');
        return view('fm.rename',compact('oldName','type','path'));
//        return  $this->fileSystem->rename($path,$oldName,$newName);

    }

    public function rename(RenameRequest  $request)
    {
        $data=$request->all();
        return $this->fileSystem->rename($data);

    }

    public function remove(Request $request){
        $data=$request->all();
        return $this->fileSystem->remove($data);
      //  return $this->fileSystem->remove($data);

    }

    public function moveView(Request $request){
        $from=$request->input('path');
        $type=$request->input('type');
        $file_name=$request->input('filename');
        $operator=$request->input('operator');
        $tree=$this->fileSystem->getTreeStructure('p_test',true,'dir');
        return view('fm.tree',compact('tree','type','from','file_name','operator'));

    }

    public function move(Request $request)
    {
        $data = $request->all();
        return $this->fileSystem->moveOperation($data);
    }

        public function copy(Request $request){
            $data = $request->all();
            return $this->fileSystem->copyOperation($data);
    }

    public function createNew(CreateNewRequest $request)
    {
        $data=$request->all();
        return $this->fileSystem->createNew($data);

    }

    public function createNewView(Request $request)
    {
        $path=$request->input('path');
        $type=$request->input('type');
        return view('fm.create',compact('type','path'));

    }

    public function downloadSingle(Request $request)
    {
        $data=$request->all();
        if ($data['type']=='file'){
            return  $this->fileSystem->getUrlLink($data['path']);;
        }
//        else if ($data['type']=='dir'){
//
//        }
    }

    public function writeFile(Request $request){
        $data=$request->all();
        return $this->fileSystem->writeToFile($data);
    }
}
