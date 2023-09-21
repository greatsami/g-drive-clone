<?php

namespace App\Http\Controllers;

use App\Http\Requests\AddToFavouritesRequest;
use App\Http\Requests\FilesActionRequest;
use App\Http\Requests\ShareFileRequest;
use App\Http\Requests\StoreFileRequest;
use App\Http\Requests\StoreFolderRequest;
use App\Http\Requests\TrashFileRequest;
use App\Http\Resources\FileResource;
use App\Jobs\UploadFileToCloudJob;
use App\Mail\ShareFilesMail;
use App\Models\File;
use App\Models\FileShare;
use App\Models\StarredFile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class FileController extends Controller
{
    public function myFiles(Request $request, string $folder = null)
    {
        $search = $request->get('search');
        $favourites = (int)$request->get('favourites');

        if ($folder) {
            $folder = File::query()
                ->where('created_by', Auth::id())
                ->where('path', $folder)
                ->firstOrFail();
        } else {
            $folder = $this->getRoot();
        }

        $query = File::query()
            ->select('files.*')
            ->with('starred')
            ->where('created_by', Auth::id())
            ->where('_lft', '!=', 1)
            ->orderBy('is_folder', 'desc')
            ->orderBy('files.created_at', 'desc')
            ->orderBy('files.id', 'desc');

        if ($search) {
            $query->where('name', 'LIKE', "%$search%");
        } else {
            $query->where('parent_id', $folder->id);
        }

        if ($favourites === 1) {
            $query->join('starred_files', 'starred_files.file_id', '=', 'files.id')
                ->where('starred_files.user_id', Auth::id());
        }

        $files = $query->paginate(10);

        $files = FileResource::collection($files);

        if ($request->wantsJson()) {
            return $files;
        }

        // $files = FileResource::collection($files);

        $ancestors = FileResource::collection([...$folder->ancestors, $folder]);

        $folder = new FileResource($folder);

        return inertia()->render('MyFiles', compact('files', 'folder', 'ancestors'));
    }

    public function createFolder(StoreFolderRequest $request)
    {

        $data = $request->validated();
        $parent = $request->parent;

        if (!$parent) {
            $parent = $this->getRoot();
        }

        $file = new File();
        $file->is_folder = 1;
        $file->name = $data['name'];
        $parent->appendNode($file);
    }

    public function store(StoreFileRequest $request)
    {
        $data = $request->validated();
        $parent = $request->parent;
        $user = $request->user();
        $fileTree = $request->file_tree;

        if (!$parent) {
            $parent = $this->getRoot();
        }
        if (!empty($fileTree)) {
            $this->saveFileTree($fileTree, $parent, $user);
        } else {
            foreach ($data['files'] as $file) {
                /** @var UploadedFile $file */
                $this->saveFile($file, $user, $parent);
            }
        }
    }

    public function destroy(FilesActionRequest $request)
    {
        $data = $request->validated();
        $parent = $request->parent;

        if ($data['all']) {
            $children = $parent->children;

            foreach ($children as $child) {
                $child->moveToTrash();
            }
        } else {
            foreach ($data['ids'] ?? [] as $id) {
                $file = File::find($id);
                if ($file) {
                    $file->moveToTrash();
                }
            }
        }
        return to_route('myFiles', ['folder' => $parent->path]);
    }

    public function trash(Request $request)
    {
        $search = $request->get('search');

        $query = File::onlyTrashed()
            ->where('created_by', Auth::id())
            ->orderBy('is_folder', 'desc')
            ->orderBy('deleted_at', 'desc')
            ->orderBy('files.id', 'desc');

        if ($search){
            $query->where('name', 'LIKE', "%$search%");
        }

        $files = $query->paginate(10);

        if ($request->wantsJson()) {
            return $files;
        }

        $files = FileResource::collection($files);
        return inertia()->render('Trash', compact('files'));
    }

    public function download(FilesActionRequest $request)
    {
        $data = $request->validated();
        $parent = $request->parent;

        $all = $data['all'] ?? false;
        $ids = $data['ids'] ?? [];

        if (!$all && empty($ids)) {
            return [
                'message' => 'Please select files to download'
            ];
        }

        $zipName = $parent->name;
        if ($all) {
            $url = $this->createZip($parent->children);
            $fileName = $zipName.'.zip';
        } else {
            [$url, $fileName] = $this->getDownloadUrl($ids, $zipName);
        }

        return [
            'url' => $url,
            'filename' => $fileName
        ];
    }

    public function downloadSharedWithMe(FilesActionRequest $request)
    {
        $data = $request->validated();

        $all = $data['all'] ?? false;
        $ids = $data['ids'] ?? [];

        if (!$all && empty($ids)) {
            return [
                'message' => 'Please select files to download'
            ];
        }

        $zipName = 'shared_with_me_'.time();

        if ($all) {
            $files = File::getSharedWithMe()->get();
            $url = $this->createZip($files);
            $fileName = $zipName.'.zip';
        } else {
            [$url, $fileName] = $this->getDownloadUrl($ids, $zipName);
        }

        return [
            'url' => $url,
            'filename' => $fileName,
        ];
    }

    public function downloadSharedByMe(FilesActionRequest $request)
    {
        $data = $request->validated();

        $all = $data['all'] ?? false;
        $ids = $data['ids'] ?? [];

        if (!$all && empty($ids)) {
            return [
                'message' => 'Please select files to download'
            ];
        }

        $zipName = 'shared_by_me_'.time();

        if ($all) {
            $files = File::getSharedByMe()->get();
            $url = $this->createZip($files);
            $fileName = $zipName.'.zip';
        } else {
            [$url, $fileName] = $this->getDownloadUrl($ids, $zipName);
        }

        return [
            'url' => $url,
            'filename' => $fileName,
        ];
    }

    public function addToFavourites(AddToFavouritesRequest $request)
    {
        $data = $request->validated();
        $id = $data['id'];
        $file = File::find($id);
        $user_id = Auth::id();

        $starredFile = StarredFile::query()->where('file_id', $id)->where('user_id', $user_id)->first();
        if ($starredFile) {
            $starredFile->delete();
        } else {
            StarredFile::create([
                'file_id' => $file->id,
                'user_id' => $user_id,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        }

        return back();
    }

    public function restore(TrashFileRequest $request)
    {
        $data = $request->validated();
        if ($data['all']) {
            $children = File::onlyTrashed()->get();
            foreach ($children as $child) {
                $child->restore();
            }
        } else {
            $ids = $data['ids'] ?? [];
            $children = File::onlyTrashed()->whereIn('id', $ids)->get();
            foreach ($children as $child) {
                $child->restore();
            }
        }

        return to_route('trash');
    }

    public function deleteForEver(TrashFileRequest $request)
    {
        $data = $request->validated();
        if ($data['all']) {
            $children = File::onlyTrashed()->get();
            foreach ($children as $child) {
                $child->deleteForEver();
            }
        } else {
            $ids = $data['ids'] ?? [];
            $children = File::onlyTrashed()->whereIn('id', $ids)->get();
            foreach ($children as $child) {
                $child->deleteForEver();
            }
        }

        return to_route('trash');
    }

    public function share(ShareFileRequest $request)
    {
        $data = $request->validated();
        $parent = $request->parent;

        $all = $data['all'] ?? false;
        $email = $data['email'] ?? false;
        $ids = $data['ids'] ?? [];

        if (!$all && empty($ids)) {
            return [
                'message' => 'Please select files to share'
            ];
        }

        $user = User::query()->where('email', $email)->first();
        if (!$user) {
            return redirect()->back();
        }

        if ($all) {
            $files = $parent->children;
        } else {
            $files = File::find($ids);
        }

        $data = [];
        $ids = Arr::pluck($files, 'id');
        $existingFileIds = FileShare::query()->whereIn('file_id', $ids)->where('user_id', $user->id)->get()->keyBy('file_id');

        foreach ($files as $file) {
            if ($existingFileIds->has($file->id)) {
                continue;
            }
            $data[] = [
                'file_id' => $file->id,
                'user_id' => $user->id,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ];
        }

        FileShare::insert($data);

        // TODO send email address
        Mail::to($user)->send(new ShareFilesMail($user, Auth::user(), $files));

        return back();
    }

    public function sharedWithMe(Request $request)
    {
        $search = $request->get('search');

        $query = File::getSharedWithMe();
        if ($search){
            $query->where('name', 'LIKE', "%$search%");
        }
        $files = $query->paginate(10);

        if ($request->wantsJson()) {
            return $files;
        }

        $files = FileResource::collection($files);
        return inertia()->render('SharedWithMe', compact('files'));
    }

    public function sharedByMe(Request $request)
    {
        $search = $request->get('search');

        $query = File::getSharedByMe();
        if ($search){
            $query->where('name', 'LIKE', "%$search%");
        }
        $files = $query->paginate(10);

        $files = FileResource::collection($files);
        return inertia()->render('SharedByMe', compact('files'));
    }

    private function getRoot()
    {
        return File::query()->whereIsRoot()->where('created_by', Auth::id())->firstOrFail();
    }

    private function saveFile($file, $user, $parent): void
    {
        $path = $file->store('/files/' . $user->id, 'local');

        $model = new File();
        $model->storage_path = $path;
        $model->is_folder = false;
        $model->name = $file->getClientOriginalName();
        $model->mime = $file->getMimeType();
        $model->size = $file->getSize();
        $model->uploaded_on_cloud = false;

        $parent->appendNode($model);

        // TODO start background job for file upload
        UploadFileToCloudJob::dispatch($model);
    }

    public function createZip($files): string
    {
        $zipPath = 'zip/' . Str::random() . '.zip';
        $publicPath = "$zipPath";

        if (!is_dir(dirname($publicPath))) {
            Storage::disk('public')->makeDirectory(dirname($publicPath));
        }

        $zipFile = Storage::disk('public')->path($publicPath);

        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $this->addFileToZip($zip, $files);
        }
        $zip->close();

        return asset(Storage::disk('local')->url($zipPath));
    }

    public function saveFileTree($fileTree, $parent, $user)
    {
        foreach ($fileTree as $name => $file) {
            if (is_array($file)) {
                $folder = new File();
                $folder->is_folder = 1;
                $folder->name = $name;

                $parent->appendNode($folder);
                $this->saveFileTree($file, $folder, $user);
            } else {

                $this->saveFile($file, $user, $parent);
            }
        }
    }

    private function addFileToZip($zip, $files, $ancestors = '')
    {
        foreach ($files as $file) {
            if ($file->is_folder) {
                $this->addFileToZip($zip, $file->children, $ancestors . $file->name . '/');
            } else {
                $localPath = Storage::disk('local')->path($file->storage_path);
                if ($file->uploaded_on_cloud == 1) {
                    $dest = pathinfo($file->storage_path, PATHINFO_BASENAME);
                    $content = Storage::get($file->storage_path);
                    Storage::disk('public')->put($dest, $content);
                    $localPath = Storage::disk('public')->path($dest);
                }

                $zip->addFile($localPath, $ancestors . $file->name);
            }
        }
    }

    private function getDownloadUrl(array $ids, $zipName)
    {
        if (count($ids) === 1) {
            $file = File::find($ids[0]);
            if ($file->is_folder) {
                if ($file->children->count() === 0) {
                    return [
                        'message' => 'The folder is empty'
                    ];
                }
                $url = $this->createZip($file->children);
                $fileName = $file->name . '.zip';
            } else {
                $dest = pathinfo($file->storage_path, PATHINFO_BASENAME);
                if ($file->uploaded_on_cloud == 1) {
                    $content = Storage::get($file->storage_path);
                } else {
                    $content = Storage::disk('local')->get($file->storage_path);
                }

                Storage::disk('public')->put($dest, $content);
                $url = asset(Storage::disk('public')->url($dest));
                $fileName =$file->name;
            }
        } else {
            $files = File::query()->whereIn('id', $ids)->get();
            $url = $this->createZip($files);
            $fileName = $zipName . '.zip';
        }

        return [$url, $fileName];
    }
}