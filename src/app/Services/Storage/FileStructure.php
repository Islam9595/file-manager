<?php

namespace Ie\FileManager\App\Services\Storage;


use Ie\FileManager\App\Events\Deleted;
use Ie\FileManager\App\Events\DirectoryCreated;
use Ie\FileManager\App\Events\FileCreated;
use Ie\FileManager\App\Events\FilesUploaded;
use Ie\FileManager\App\Events\Paste;
use Ie\FileManager\App\Events\Rename;
use Ie\FileManager\App\Factory\Node;
use Ie\FileManager\App\Factory\NodeFactory;
use Ie\FileManager\App\Jobs\RenameJob;
use Ie\FileManager\App\Models\FilePermission;
use Ie\FileManager\App\Services\Cache\Adapters\CacheSystem;
use Ie\FileManager\App\Services\Cache\Adapters\RedisServer;
use Ie\FileManager\App\Utils;
use Dflydev\DotAccessData\Data;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use League\Flysystem\FileNotFoundException;
use Pion\Laravel\ChunkUpload\Handler\HandlerFactory;
use Pion\Laravel\ChunkUpload\Receiver\FileReceiver;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Mime\MimeTypes;

class FileStructure
{
    public $storage;
    protected $cache;
    protected $tree;
    protected $isCacheUsed;
    protected $separator='/';
    protected $adapter;
    protected $disk;
    protected $config;
    protected $allowedPermission=[];
    /**
     * @var mixed
     */
    protected $cacheTimeout;
    /**
     * @var mixed
     */
    private $filePermissions=null;
    private $adapterInstance;

    public function __construct()
    {
        $this->config = config('service_configuration');
        $credential=$this->config['services']['App\Services\Storage\FileStructure'];
        $adapter = $credential['config']['adapter'];
        $this->adapterInstance=$adapter();
        $this->disk=$credential['config']['disk'];
        $this->storage = new \League\Flysystem\Filesystem($this->adapterInstance);
        $this->setCacheServerIfUsed($this->config['services']);
    }

    public function createDir(string $path, string $name)
    {
        $destination = $this->joinPaths($this->applyPathPrefix($path), $name);

        while (! empty($this->storage->listContents($destination, true))) {
            $destination = $this->upcountName($destination);
        }

        return $this->storage->createDir($destination);
    }

    public function createFile(string $path, string $name)
    {
        $destination = $this->joinPaths($this->applyPathPrefix($path), $name);

        while ($this->storage->has($destination)) {
            $destination = $this->upcountName($destination);
        }

        $this->storage->put($destination, '');
    }

    public function fileExists(string $path)
    {
        $path = $this->applyPathPrefix($path);

        return $this->storage->has($path);
    }

    public function isDir(string $path)
    {
        $path = $this->applyPathPrefix($path);

        return $this->storage->getSize($path) === false;
    }

    public function copy(string $source, string $destination)
    {
        $source = $this->applyPathPrefix($source);
        $destination = $this->joinPaths($this->applyPathPrefix($destination), $this->getBaseName($source));

        while ($this->storage->has($destination)) {
            $destination = $this->upcountName($destination);
        }

        return $this->storage->copy($source, $destination);
    }

    public function copyDir(string $source, string $destination,string $operation)
    {
        $source = $this->applyPathPrefix($this->addSeparators($source));
        $destination = $this->applyPathPrefix($this->addSeparators($destination));
        $source_dir = $this->getBaseName($source);
        $real_destination = $this->joinPaths($destination, $source_dir);

        while (! empty($this->storage->listContents($real_destination, true))) {
            $real_destination = $this->upcountName($real_destination);
        }

        $contents = $this->storage->listContents($source, true);

        if (empty($contents)) {
            $this->storage->createDir($real_destination);
        }

        foreach ($contents as $file) {
            $source_path = $this->separator.ltrim($file['path'], $this->separator);
            $path = substr($source_path, strlen($source), strlen($source_path));

            if ($file['type'] == 'dir') {
                $this->storage->createDir($this->joinPaths($real_destination, $path));

                continue;
            }

            if ($file['type'] == 'file') {
                $this->storage->copy($file['path'], $this->joinPaths($real_destination, $path));
            }
        }
        if ($operation=='Move'){
           $this->deleteDir($source);
        }
    }

    public function deleteDir(string $path)
    {
        return $this->storage->deleteDir($this->applyPathPrefix($path));
    }

    public function deleteFile(string $path)
    {
        return $this->storage->delete($this->applyPathPrefix($path));
    }

    public function readStream(string $path): array
    {
        if ($this->isDir($path)) {
            throw new \Exception('Cannot stream directory');
        }

        $path = $this->applyPathPrefix($path);

        return [
            'filename' => $this->getBaseName($path),
            'stream' => $this->storage->readStream($path),
            'filesize' => $this->storage->getSize($path),
        ];
    }

    public function read(string $path)
    {
//        if ($this->isDir($path)) {
//            throw new \Exception('Cannot stream directory');
//        }

        $path = $this->applyPathPrefix($path);

        return $this->storage->read($path);

        return [
            'filename' => $this->getBaseName($path),
            'stream' => $this->storage->readStream($path),
            'filesize' => $this->storage->getSize($path),
        ];
    }

    public function move(string $from, string $to): bool
    {
        $from = $this->applyPathPrefix($from);
        $to = $this->applyPathPrefix($to);

        while ($this->storage->has($to)) {
            $to = $this->upcountName($to);
        }

        return $this->storage->rename($from, $to);
    }

    public function renameFile(string $from, string $to)
    {
//        $from = $this->joinPaths($this->applyPathPrefix($destination), $from);
//        $to = $this->joinPaths($this->applyPathPrefix($destination), $to);
//
//        while ($this->storage->has($to)) {
//            $to = $this->upcountName($to);
//        }
        $parent=$this->getParent($from);
        $this->storage->rename($from, $parent.$this->separator.$to);
        $this->cache->forgetFromCacheServer($parent);
        $this->rebuildCacheStructure($parent,false);
    }

    public function renameFolder(string $path,string $from, string $to): bool
    {
        $this->storage->createDir($this->getParent($path).$to);
        $directorytList= $this->getOrStoreCollectionCache($path,true);
        if ($this->storage->getAdapter() instanceof  AwsS3Adapter){
          //  dd("aws s3 --recursive mv s3://".env('AWS_BUCKET')."/$path s3://".env('AWS_BUCKET').$this->getParent($path).$this->separator.$to);
            exec("aws s3 --recursive mv s3://".env('AWS_BUCKET')."/$path s3://".env('AWS_BUCKET').$this->getParent($path).$this->separator.$to,$output);
            $this->deleteDir($path);
            if (count($output)==0){
                $this->createDir($this->getParent($path),$to);
            }
        }
        else{
            foreach ($directorytList as $directorytListItem){
                if($directorytListItem['type']=='file'){
                    $this->move($directorytListItem['path'],str_replace($from,$to,$directorytListItem['path']));
                }
                else if($directorytListItem['type']=='dir'){
                    $this->storage->createDir(str_replace($from,$to,$directorytListItem['path']));
                }

            }
        }
        $this->cache->forgetFromCacheServer($this->getParent($path));
        $this->rebuildCacheStructure($this->getParent($path),false);
        return  true;
      //  return $this->deleteDir($path);
    }


    public function store(string $path, string $name, $resource, bool $overwrite = false): bool
    {
        $destination = $this->joinPaths($this->applyPathPrefix($path), $name);

        while ($this->storage->has($destination)) {
            if ($overwrite) {
                $this->deleteFile($destination);
            } else {
                $destination = $this->upcountName($destination);
            }
        }

        return $this->storage->putStream($destination, $resource);
    }

    public function setPathPrefix(string $path_prefix)
    {
        $this->path_prefix = $this->addSeparators($path_prefix);
    }

    public function getSeparator()
    {
        return $this->separator;
    }

    public function getPathPrefix(): string
    {
        return $this->separator;
    }

    public function clearPath($path): string
    {
        if ($this->startsWith($path,$this->separator)){
            return  substr($path,1);
        }
        return $path;
    }

    function startsWith ($string, $startString)
    {
        $len = strlen($startString);
        return (substr($string, 0, $len) === $startString);
    }

    /**
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function getDirectoryStructure(string $path , bool $recursive = false,$cache=true): Collection
    {
        $path=$this->clearPath($path);
       // $path=substr($path,1);
//        $old_path=$path;
//        $path=$this->applyPathPrefix($path);
        if ($this->isCacheUsed){
            $collection=collect($this->getOrStoreCollectionCache($this->applyPathPrefix($path), $recursive,$cache));
        }
        else{
            $collection=collect($this->storage->listContents($path, $recursive));
        }
        $this->filePermissions=app()->make($this->config['filePermissions']);;
        $availablity=$this->filePermissions->getAvailablity();
        if ($availablity){
            $allowed_permissions=$this->filePermissions->getPermissions($path);
            $allowed_permissions=$allowed_permissions
                ->pluck('path')
                ->toArray();
            $collection=collect($collection)
                ->whereIn('path',$allowed_permissions);
        }
            $back= [
                'type' => 'back',
                'path' => $this->getParent($path),
                'filename' => '..',
                'size' => null,
                'time' => null
            ];
        $collection->prepend($back);
        return $collection;
    }

    public function getParent(string $dir): string
    {
        if (! $dir || $dir == $this->separator || ! trim($dir, $this->separator)) {
            return $this->separator;
        }
        $tmp = explode($this->separator, trim($dir, $this->separator));
        array_pop($tmp);

        return $this->separator.trim(implode($this->separator, $tmp), $this->separator);
    }

    public function  getAllParents(string $path,$include=true,$exclude=[]): array
    {
//        $path = substr($path, 1);
//        dd($path);
        $done=false;
        if ($include && $path!=$this->separator){
            if (!in_array($path,$exclude)){
                $item['name']=$this->getBaseName($path);
                $item['path']=$this->applyPathPrefix($path);
                $parents[] =$item;
            }
        }
        while(!$done) {
            $path = $this->getParent($path);
            if (!in_array($path,$exclude) && $path!=$this->separator){
                $item['name']=$this->getBaseName($path);
                $item['path']=$this->applyPathPrefix($path);
                $parents[] =$item;
            }
            else{
                $done=true;
            }
        }
        if (!in_array($path,$exclude)) {
            $item['name'] = 'Home';
            $item['path'] =  $this->separator;
            $parents[] = $item;
        }
        return array_reverse($parents);
    }

    public function getDirectoryBreadcrumb($mainPath): array
    {
        $breadcrumbs=[];
        $breadcrumb=[];
        $breadcrumb['name']='home';
        $breadcrumb['path']=$this->separator;
        $breadcrumbs[]=$breadcrumb;
        $mainPath=$this->applyPathPrefix($mainPath);
        if ($mainPath!=$this->separator){
            $breadcrumbStructure=explode($this->separator,$mainPath);
            foreach ($breadcrumbStructure as $breadcrumb_item){
                $breadcrumb=[];
                $breadcrumb['name']=$breadcrumb_item;
                $breadcrumb['path']=substr($mainPath, 0, strpos($mainPath, $breadcrumb_item)).$breadcrumb_item;
                $breadcrumbs[]=$breadcrumb;
            }
        }
        return  $breadcrumbs;
    }

    public function getTreeStructure($mainPath,$recursive=true,$type='all',$forceNotUseCache=false): array
    {
      // return Storage::disk($this->disk)->directories($mainPath);
        if ($this->isCacheUsed && !$forceNotUseCache){
            $encoded_tree=$this->getOrStoreCollectionCache($mainPath, $recursive);
            $this->tree= collect($encoded_tree);
        }
        else{
         $this->tree= collect($this->storage->listContents($mainPath, $recursive));
        }
        if ($type!='all'){
            $this->tree= $this->tree->where('type',$type);
        }
        return $this->listFolderFiles($mainPath);

    }

    public function listFolderFiles($mainPath): array
    {
        if ($mainPath==$this->separator){
            $mainPath='';
        }
        $nodes = [];
        $factory = new NodeFactory();
        $fileOrFolderList=$this->tree->where('dirname',$mainPath);
        if (count($fileOrFolderList) > 0) {
            foreach ($fileOrFolderList as $fileOrFolder) {
                $type=$fileOrFolder['type'];
                $path=$fileOrFolder['path'];
                $filename=$fileOrFolder['filename'];
                $children=null;
                $extension=null;
                if ($fileOrFolder['type']=='dir'){
                    $children=$this->listFolderFiles($fileOrFolder['path']);
                }
                else if($fileOrFolder['type']=='file'){
                    $extension=$fileOrFolder['extension'];
                }
                $nodes[]=$factory->createNode($type, $path, $filename, $children, $extension);;
            }
        }
        return $nodes;
    }

    public function download($path, string $filename)
    {
        return $this->readStream($path);
    }

    public function getSize($path)
    {
        return $this->storage->getSize($path);
    }


    public function getBaseDownloadable($path)
    {
        return $this->storage->getAdapter()->read($path);
    }

    public function readFileMetaData($path)
    {
      //  return  $this->storage->getAdapter()->getMimetype($path);
        try {
            return $this->storage->getAdapter()->read($path);
        } catch (FileNotFoundException $e) {
        }
    }

    public function getMimetype($path)
    {
        return  $this->storage->getAdapter()->getMimetype($path);
    }

    /**
     * @throws FileNotFoundException
     */
    public function getUrlLink($path)
    {
        if (!preg_match('/^[\x20-\x7e]*$/', basename($path))) {
            $filename = Str::ascii(basename($path));
        } else {
            $filename = basename($path);
        }
        return Storage::disk($this->getDisk())->download($path, $filename);

        //return Storage::disk($this->disk)->url($path);
    }

    public function getUrlLinkForDownload($path)
    {
        if (!preg_match('/^[\x20-\x7e]*$/', basename($path))) {
            $filename = Str::ascii(basename($path));
        } else {
            $filename = basename($path);
        }
        return Storage::disk($this->getDisk())->url($path);

        //return Storage::disk($this->disk)->url($path);
    }

    public function rename($data)
    {
        $this->filePermissions=app()->make($this->config['filePermissions']);
        if (!$this->filePermissions->getFileManagerPermissions()  ||
            ($this->filePermissions->getFileManagerPermissions() && $this->userHasPermissionToFile(Utils::WRITE,$data['path']))) {
            $type = $data['type'];
            if ($type == 'file') {
                $this->renameFile($data['path'], $data['newName']);
            } else if ($type == 'dir') {
                $this->renameFolder($data['path'], $data['old_name'], $data['newName']);
            }
            event(new Rename($data['old_name'], $data['newName'], $data['path'], $type, $this->disk));
        }
        else{
            return json_encode(['code'=>403,'status'=>'Forbidden']);
        }

    }

    /**
     * @throws \Exception
     */
    public function remove(array $data)
    {
        //  $paths=collect($data['paths'])->pluck('path')->toArray();
//        if ($this->storage->getAdapter() instanceof AwsS3Adapter) {
//            $to_remove = '';
//            foreach ($data['paths'] as $index => $item) {
//                $item['type'] == 'dir' ? $recursive = '--recursive' : $recursive = '';
//                $to_remove .= "aws s3  rm " . $recursive . " s3://" . env('AWS_BUCKET') . $this->separator . $item['path'];
//                if (array_key_last($data['paths']) != $index) {
//                    $append = ' && ';
//                    $to_remove .= $append;
//                }
//                $this->cache->forgetFromCacheServer($this->getParent($item['path']));
//            }
//            exec($to_remove);
//        } else {
        $this->filePermissions=app()->make($this->config['filePermissions']);
        if (!$this->filePermissions->getFileManagerPermissions()  ||
            ($this->filePermissions->getFileManagerPermissions() && $this->userHasPermissionToFile(Utils::WRITE,$data['main_path']))) {
            $pathsToDeleted = [];
            foreach ($data['paths'] as $item) {
                if ($item['type'] == 'file') {
                    $pathsToDeleted[] = $item['path'];
                } elseif ($item['type'] == 'dir') {
                    $this->deleteDir($item['path']);
                } else {
                    throw new \Exception('Type must be dir or file');
                }
                event(new Deleted($data, $this->disk));
//            }
            }
            Storage::disk($this->disk)->delete($pathsToDeleted);
            $this->cache->forgetFromCacheServer($this->getParent($item['path']));
            $this->rebuildCacheStructure($this->getParent($item['path']),false);
        }
        else{
            return json_encode(['code'=>403,'status'=>'Forbidden']);
        }
       // }
    }

    public function moveFile(array $data,$destination)
    {
        $this->move($data['from_path'],$destination.$this->separator.$data['file_name']);
        event(new Paste('Move',$data['from_path'],$destination.$this->separator.$data['file_name'],$data['file_name'],'file',$this->getDisk()));
        $this->cache->forgetFromCacheServer($this->getParent($data['from_path']));
        $this->cache->forgetFromCacheServer($this->applyPathPrefix($destination));;
        $this->rebuildCacheStructure($this->getParent($data['from_path']),false);
        $this->rebuildCacheStructure($this->applyPathPrefix($destination),false);

    }

    public function moveOperation($data){
        $destination=$data['to_path'];
        $this->filePermissions=app()->make($this->config['filePermissions']);
        if (!$this->filePermissions->getFileManagerPermissions()  ||
            ($this->filePermissions->getFileManagerPermissions() && $this->userHasPermissionToFile(Utils::WRITE,$destination))) {
            $items = json_decode($data['data']['paths'], 1);
            foreach ($items as $item) {
                if ($item['type'] == 'file') {
                    $this->moveFile($item, $destination);
                } else if ($item['type'] == 'dir') {
                    $this->copyOrMoveDir($item, $destination);
                }
            }
        }
        else{
            return json_encode(['code'=>403,'status'=>'Forbidden']);
        }
    }
    public function copyOperation($data){
        $destination=$data['to_path'];
        $this->filePermissions=app()->make($this->config['filePermissions']);
        if (!$this->filePermissions->getFileManagerPermissions()  ||
            ($this->filePermissions->getFileManagerPermissions() && $this->userHasPermissionToFile(Utils::WRITE,$destination))) {
            $items=json_decode($data['data']['paths'],1);
            foreach ($items as $item){
                if ($item['type']=='file'){
                    $this->copyFile($item,$destination);
                }
                else if ($item['type']=='dir'){
                    $this->copyOrMoveDir($item,$destination);
                }
            }
        }
        else{
            return json_encode(['code'=>403,'status'=>'Forbidden']);
        }
    }

    public function copyFile(array $data,$destination)
    {
        //dd($data['from_path'],$data['to_path'].$this->separator.$data['file_name']);
        $result= $this->copy($data['from_path'],$destination);
        event(new Paste('Copy',
            $data['from_path'],
            $destination.$this->separator.$data['file_name'],
            $data['file_name'],
            'file',$this->getDisk()
        ));
        $this->cache->forgetFromCacheServer($this->getParent($data['from_path']));
        $this->cache->forgetFromCacheServer($this->applyPathPrefix($destination));
        $this->rebuildCacheStructure($this->getParent($data['from_path']),false);
        $this->rebuildCacheStructure($this->applyPathPrefix($destination),false);

    }

    public function copyOrMoveDir(array $data,$destination)
    {
        $operation=$data['operator'];
      //  dd($data);
        if ($this->storage->getAdapter() instanceof  AwsS3Adapter){
            $path=$data['from_path'];
         //   dd("aws s3 --recursive cp s3://".env('AWS_BUCKET')."/$path s3://".env('AWS_BUCKET').$this->separator.$destination);
         //   dd("aws s3 --recursive mv s3://".env('AWS_BUCKET')."/$path s3://".env('AWS_BUCKET').$this->separator.$destination);
          //  dd("aws s3 --recursive cp s3://".env('AWS_BUCKET')."/$path s3://".env('AWS_BUCKET').$this->separator.$destination.$this->separator.$data['file_name']);
            $overrwite_path=$path;
          //  dd("aws s3 --recursive cp s3://".env('AWS_BUCKET')."/$path s3://".env('AWS_BUCKET').$destination.$overrwite_seperator.$data['file_name']);
        //    exec('aws configure list',$o,$c);
      //      dd("aws s3 --recursive cp s3://".env('AWS_BUCKET')."/$path s3://".env('AWS_BUCKET').$this->separator.$destination.$overrwite_seperator.$data['file_name']);
          //  dd("aws s3 --recursive cp s3://".env('AWS_BUCKET')."/$overrwite_path"." s3://".env('AWS_BUCKET').$this->separator.$this->applyCorrectPath($destination).$this->separator.$data['file_name']);
            exec("aws s3 --recursive cp s3://".env('AWS_BUCKET')."/$overrwite_path"." s3://".env('AWS_BUCKET').$this->separator.$this->applyCorrectPath($destination).$this->separator.$data['file_name']);
            if ($operation=='Move'){
                $this->deleteDir($data['from_path']);
//                Storage::disk($this->disk)->delete([$data['from_path']]);
            }
        }
        else {
            $this->copyDir($data['from_path'],$destination,$operation);
        }
        event(new Paste($operation,$data['from_path'],$destination,$data['file_name'],'dir',$this->getDisk()));
        if ($operation=='Copy'){
           $this->cache->forgetFromCacheServer($this->applyPathPrefix($destination));
            $this->rebuildCacheStructure($this->applyPathPrefix($destination),false);
        }
        elseif($operation=='Move'){
            $this->cache->forgetFromCacheServer($this->applyPathPrefix($destination));
            $this->cache->forgetFromCacheServer($this->getParent($data['from_path']));
            $this->rebuildCacheStructure($this->applyPathPrefix($destination),false);
            $this->rebuildCacheStructure($this->getParent($data['from_path']),false);
        }
    }

    public function createNew(array $data)
    {
        $this->filePermissions=app()->make($this->config['filePermissions']);
        if (!$this->filePermissions->getFileManagerPermissions()  ||
            ($this->filePermissions->getFileManagerPermissions() && $this->userHasPermissionToFile(Utils::CREATE,$data['path']))){
            if ($data['type']=='dir'){
                $this->createDir($data['path'],$data['newName']);
                event(new DirectoryCreated($data['newName'],$data['path'],$this->disk));
            }
            else if ($data['type']=='file'){
                $this->createFile($data['path'],$data['newName']);
                event(new FileCreated($data['newName'],$data['path'],$this->disk));
            }
            if ($this->isCacheUsed){
                $this->cache->forgetFromCacheServer($this->applyPathPrefix($data['path']));
                $s=$this->rebuildCacheStructure($this->applyPathPrefix($data['path']),false);
            }
            return  true;
        }
        else{
            return json_encode(['code'=>403,'status'=>'Forbidden']);
        }
    }

    public function readMediaMetaData($path)
    {
//        if (!preg_match('/^[\x20-\x7e]*$/', basename($path))) {
//            $filename = Str::ascii(basename($path));
//        } else {
//            $filename = basename($path);
//        }
//
        return Storage::disk($this->disk)->download($path);
    }

    public function writeToFile(array $data)
    {
        $content = $data['contents'];
        $path = $data['path'];
        $this->filePermissions = app()->make($this->config['filePermissions']);
        if (!$this->filePermissions->getFileManagerPermissions() ||
            ($this->filePermissions->getFileManagerPermissions() && $this->userHasPermissionToFile(Utils::WRITE, $path))) {
                $stream = tmpfile();
                fwrite($stream, $content);
                rewind($stream);
                $this->storage->putStream($path, $stream);
                return json_encode(['code' => 200, 'status' => 'Done']);
        }
        else {
            return json_encode(['code' => 403, 'status' => 'Forbidden']);
        }
    }

    public function buildBreadcrumbStructure(string $mainPath): array
    {
       return $this->getAllParents($mainPath,true);
    }

    public function getDirectories($mainPath)
    {
        return Storage::disk($this->disk)->directories($mainPath);
    }

    public function filterDirectoryStructure(Collection $contents, string $type): array
    {
        return $contents->where('type',$type)->toArray();
    }

    public function setRootPath($name)
    {
        $this->separator=$name;
    }

    public function getRootPath()
    {
        return $this->separator;
    }

    protected function  getBreadcrumbFullPath($path,$breadcrumb_item){
    }

    protected function upcountCallback($matches)
    {
        $index = isset($matches[1]) ? intval($matches[1]) + 1 : 1;
        $ext = isset($matches[2]) ? $matches[2] : '';

        return ' ('.$index.')'.$ext;
    }

    protected function upcountName($name)
    {
        return preg_replace_callback(
            '/(?:(?: \(([\d]+)\))?(\.[^.]+))?$/',
            [$this, 'upcountCallback'],
            $name,
            1
        );
    }

    private function applyPathPrefix(string $path): string
    {
        if ($path == '..'
            || strpos($path, '..'.$this->separator) !== false
            || strpos($path, $this->separator.'..') !== false
        ) {
            $path = $this->separator;
        }
        return $this->joinPaths($this->getPathPrefix(), $path);
    }

    private function stripPathPrefix(string $path): string
    {
        $path = $this->separator.ltrim($path, $this->separator);

        if (substr($path, 0, strlen($this->getPathPrefix())) == $this->getPathPrefix()) {
            $path = $this->separator.substr($path, strlen($this->getPathPrefix()));
        }

        return $path;
    }

    private function addSeparators(string $dir): string
    {
        if (! $dir || $dir == $this->separator || ! trim($dir, $this->separator)) {
            return $this->separator;
        }

        return $this->separator.trim($dir, $this->separator).$this->separator;
    }

    private function joinPaths(string $path1, string $path2): string
    {
        if (! $path2 || ! trim($path2, $this->separator)) {
            return $this->addSeparators($path1);
        }

        return $this->addSeparators($path1).ltrim($path2, $this->separator);
    }

    public function getBaseName(string $path): string
    {
        if (! $path || $path == $this->separator || ! trim($path, $this->separator)) {
            return $this->separator;
        }

        $tmp = explode($this->separator, trim($path, $this->separator));

        return  (string) array_pop($tmp);
    }

    public function getParentName(string $path): string
    {
        return  $this->getBaseName($this->getParent($path));
    }

    //islam done
    private function setCacheServerIfUsed($service_configuration)
    {
        $cacheCredential=$service_configuration['App\Services\Cache\CacheServerInterface'];
        $this->isCacheUsed=$cacheCredential['used'];
        if($this->isCacheUsed){

            $this->cache =new CacheSystem();
            $this->cacheTimeout=$cacheCredential['timeout'];
        }
    }

    private function getOrStoreCollectionCache(string $path, bool $recursive,$cache=true)
    {
      //$this->cache->flushAllInCacheServer();
        if (!$cache){
            $this->cache->forgetFromCacheServer($path);
        }
        if ($this->cache->existInCacheServer($path.'_'.$this->getDisk())){
                $collection=json_decode($this->cache->fetchFromCacheServer($path.'_'.$this->getDisk()),1);
            }
        else{
            $collection=  $this->rebuildCacheStructure($path,$recursive);
            }
        return $collection;
    }

    public function rebuildCacheStructure($path,$recursive){
        $collection=$this->storage->listContents($path, $recursive);
        $this->cache->storeToCacheServer($path.'_'.$this->getDisk(),json_encode($collection),$this->cacheTimeout);
        return $collection;
    }


    public function uploadLargeFiles($request)
    {
            $path_to_upload = $request->path_to_upload;
            $this->filePermissions = app()->make($this->config['filePermissions']);
            if (!$this->filePermissions->getFileManagerPermissions() ||
                ($this->filePermissions->getFileManagerPermissions() && $this->userHasPermissionToFile(Utils::WRITE, $path_to_upload))) {
                $file = $request->fileBlob;
                $extension = $file->getClientOriginalExtension();
                $check = $this->checkExistInDir($path_to_upload, $file->getClientOriginalName());
                if ($check) {
                    return [
                        'status' => false,
                        'msg' => 'file already exist'
                    ];
                }
                $fileName = $file->getClientOriginalName() . '.' . $extension; // a unique file name
                $disk = Storage::disk($this->disk);
                $path = $disk->putFileAs($path_to_upload, $file, str_replace(' ','_',$fileName));
                $disk->setVisibility($path, 'public');
                event(new FilesUploaded($path, $this->disk));
                $this->cache->forgetFromCacheServer($this->applyPathPrefix($path_to_upload));
                $this->rebuildCacheStructure($this->applyPathPrefix($path_to_upload),false);
                return [
                    'status' => true,
                    'msg' => 'done uploaded '
                ];
            } else {
                return json_encode(['code' => 403, 'status' => 'Forbidden']);
            }
    }

    private function checkExistInDir($path_to_upload, string $filename): bool
    {
        $dirList=$this->getOrStoreCollectionCache($path_to_upload,false,true);
        return !(collect($dirList)->where('basename',$filename)->count() == 0);
    }

    private function userHasPermissionToFile($permission,$path): bool
    {
      //  return false;
        if ($this->config['denyAll']){
            $this->config['filePermissions'];
            $this->filePermissions=app()->make($this->config['filePermissions']);
            $allowed_permissions=$this->filePermissions->getPermissions($this->applyCorrectPath($path),'path',true);
            if (isset($allowed_permissions)){
                $access=$allowed_permissions->access;
            }
            else{
                $access='[]';
            }
            if (in_array($permission,json_decode($access))){
                return true;
            }
            return false;
        }
        return true;
    }

    public function applyCorrectPath($path){
        if ($path==$this->separator){
            return  $this->separator;
        }
        else if (substr( $path, 0,1 )==$this->separator){
            return substr( $path, 1 );
        }
        else{
            return $path;
        }
    }

    public function getDisk(){
        return $this->disk;
    }

    public function getAdapterInstance(){
        return $this->adapterInstance;
    }

}
