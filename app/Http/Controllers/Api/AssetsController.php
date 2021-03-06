<?php
namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssetRequest;
use App\Http\Transformers\AssetsTransformer;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Location;
use App\Models\Setting;
use App\Models\User;
use Artisan;
use Auth;
use Carbon\Carbon;
use Config;
use DB;
use Gate;
use Illuminate\Http\Request;
use Input;
use Lang;
use Log;
use Mail;
use Paginator;
use Response;
use Slack;
use Str;
use TCPDF;
use Validator;
use View;


/**
 * This class controls all actions related to assets for
 * the Snipe-IT Asset Management application.
 *
 * @version    v1.0
 * @author [A. Gianotto] [<snipe@snipe.net>]
 */
class AssetsController extends Controller
{

    /**
     * Returns JSON listing of all assets
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @param int $assetId
     * @since [v4.0]
     * @return JsonResponse
     */
    public function index(Request $request)
    {
        $this->authorize('index', Asset::class);

        $allowed_columns = [
            'id',
            'name',
            'asset_tag',
            'serial',
            'model_number',
            'last_checkout',
            'notes',
            'expected_checkin',
            'order_number',
            'image',
            'assigned_to',
            'created_at',
            'updated_at',
            'purchase_date',
            'purchase_cost'
        ];

        $filter = array();
        if ($request->has('filter')) {
            $filter = json_decode($request->input('filter'));
        }


        $all_custom_fields = CustomField::all(); //used as a 'cache' of custom fields throughout this page load
        foreach ($all_custom_fields as $field) {
            $allowed_columns[]=$field->db_column_name();
        }

        $assets = Company::scopeCompanyables(Asset::select('assets.*'))->with(
            'assetloc', 'assetstatus', 'defaultLoc', 'assetlog', 'company',
            'model.category', 'model.manufacturer', 'model.fieldset','supplier');
        // If we should search on everything
        if (($request->has('search')) && (count($filter) == 0)) {
            $assets->TextSearch($request->input('search'));
        // otherwise loop through the filters and search strictly on them
        } else {
            if (count($filter) > 0) {
                $assets->ByFilter($filter);
            }
        }

        // These are used by the API to query against specific ID numbers
        if ($request->has('status_id')) {
            $assets->where('status_id', '=', $request->input('status_id'));
        }

        if ($request->has('model_id')) {
            $assets->InModelList([$request->input('model_id')]);
        }

        if ($request->has('category_id')) {
            $assets->InCategory($request->input('category_id'));
        }

        if ($request->has('location_id')) {
            $assets->ByLocationId($request->input('location_id'));
        }

        if ($request->has('supplier_id')) {
            $assets->where('assets.supplier_id', '=', $request->input('supplier_id'));
        }

        if ($request->has('company_id')) {
            $assets->where('assets.company_id', '=', $request->input('company_id'));
        }

        if ($request->has('manufacturer_id')) {
            $assets->ByManufacturer($request->input('manufacturer_id'));
        }

        $request->has('order_number') ? $assets = $assets->where('assets.order_number', '=', e($request->get('order_number'))) : '';

        $offset = request('offset', 0);
        $limit = $request->input('limit', 50);
        $order = $request->input('order') === 'asc' ? 'asc' : 'desc';


        // This is used by the sidenav, mostly
        switch ($request->input('status')) {
            case 'Deleted':
                $assets->withTrashed()->Deleted();
                break;
            case 'Pending':
                $assets->Pending();
                break;
            case 'RTD':
                $assets->RTD();
                break;
            case 'Undeployable':
                $assets->Undeployable();
                break;
            case 'Archived':
                $assets->Archived();
                break;
            case 'Requestable':
                $assets->RequestableAssets();
                break;
            case 'Deployed':
                $assets->Deployed();
                break;
        }



        // This handles all of the pivot sorting (versus the assets.* fields in the allowed_columns array)
        $column_sort = in_array($request->input('sort'), $allowed_columns) ? $request->input('sort') : 'assets.created_at';

        switch ($request->input('sort')) {
            case 'model':
                $assets->OrderModels($order);
                break;
            case 'model_number':
                $assets->OrderModelNumber($order);
                break;
            case 'category':
                $assets->OrderCategory($order);
                break;
            case 'manufacturer':
                $assets->OrderManufacturer($order);
                break;
            case 'company':
                $assets->OrderCompany($order);
                break;
            case 'location':
                $assets->OrderLocation($order);
                break;
            case 'status_label':
                $assets->OrderStatus($order);
                break;
            case 'supplier':
                $assets->OrderSupplier($order);
                break;
            case 'assigned_to':
                $assets->OrderAssigned($order);
                break;
            default:
                $assets->orderBy($column_sort, $order);
                break;
        }
        
        $total = $assets->count();
        $assets = $assets->skip($offset)->take($limit)->get();
        return (new AssetsTransformer)->transformAssets($assets, $total);
    }


     /**
     * Returns JSON with information about an asset for detail view.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @param int $assetId
     * @since [v4.0]
     * @return JsonResponse
     */
    public function show($id)
    {
        if ($asset = Asset::withTrashed()->find($id)) {
            $this->authorize('view', $asset);
            return (new AssetsTransformer)->transformAsset($asset);
        }

        return response()->json(Helper::formatStandardApiResponse('error', null, trans('admin/hardware/message.does_not_exist')), 200);
    }


    /**
     * Accepts a POST request to create a new asset
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @param Request $request
     * @since [v4.0]
     * @return JsonResponse
     */
    public function store(AssetRequest $request)
    {

        $this->authorize('create', Asset::class);

        $asset = new Asset();
        $asset->model()->associate(AssetModel::find((int) $request->get('model_id')));

        $asset->name                    = $request->get('name');
        $asset->serial                  = $request->get('serial');
        $asset->company_id              = Company::getIdForCurrentUser($request->get('company_id'));
        $asset->model_id                = $request->get('model_id');
        $asset->order_number            = $request->get('order_number');
        $asset->notes                   = $request->get('notes');
        $asset->asset_tag               = $request->get('asset_tag');
        $asset->user_id                 = Auth::id();
        $asset->archived                = '0';
        $asset->physical                = '1';
        $asset->depreciate              = '0';
        $asset->status_id               = $request->get('status_id', 0);
        $asset->warranty_months         = $request->get('warranty_months', null);
        $asset->purchase_cost           = Helper::ParseFloat($request->get('purchase_cost'));
        $asset->purchase_date           = $request->get('purchase_date', null);
        $asset->assigned_to             = $request->get('assigned_to', null);
        $asset->supplier_id             = $request->get('supplier_id', 0);
        $asset->requestable             = $request->get('requestable', 0);
        $asset->rtd_location_id         = $request->get('rtd_location_id', null);

        // Update custom fields in the database.
        // Validation for these fields is handled through the AssetRequest form request
        $model = AssetModel::find($request->get('model_id'));
        if ($model->fieldset) {
            foreach ($model->fieldset->fields as $field) {
                $asset->{$field->convertUnicodeDbSlug()} = e($request->input($field->convertUnicodeDbSlug()));
            }
        }

        if ($asset->save()) {
            $asset->logCreate();
            if ($request->get('assigned_user')) {
                $target = User::find(request('assigned_user'));
            } elseif ($request->get('assigned_asset')) {
                $target = Asset::find(request('assigned_asset'));
            } elseif ($request->get('assigned_location')) {
                $target = Location::find(request('assigned_location'));
            }
            if (isset($target)) {
                $asset->checkOut($target, Auth::user(), date('Y-m-d H:i:s'), '', 'Checked out on asset creation', e($request->get('name')));
            }
            return response()->json(Helper::formatStandardApiResponse('success', $asset, trans('admin/hardware/message.create.success')));
        }

        return response()->json(Helper::formatStandardApiResponse('error', null, $asset->getErrors()), 200);
    }


    /**
     * Accepts a POST request to update an asset
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @param Request $request
     * @since [v4.0]
     * @return JsonResponse
     */
    public function update(Request $request, $id)
    {
        $this->authorize('create', Asset::class);

        if ($asset = Asset::find($id)) {
            ($request->has('model_id')) ?
                $asset->model()->associate(AssetModel::find($request->get('model_id'))) : '';
            ($request->has('name')) ?
                $asset->name = $request->get('name') : '';
            ($request->has('serial')) ?
                $asset->serial = $request->get('serial') : '';
            ($request->has('model_id')) ?
                $asset->model_id = $request->get('model_id') : '';
            ($request->has('order_number')) ?
                $asset->order_number = $request->get('order_number') : '';
            ($request->has('notes')) ?
                $asset->notes = $request->get('notes') : '';
            ($request->has('asset_tag')) ?
                $asset->asset_tag = $request->get('asset_tag') : '';
            ($request->has('archived')) ?
                $asset->archived = $request->get('archived') : '';
            ($request->has('status_id')) ?
                $asset->status_id = $request->get('status_id') : '';
            ($request->has('warranty_months')) ?
                $asset->warranty_months = $request->get('warranty_months') : '';
            ($request->has('purchase_cost')) ?
                $asset->purchase_cost = Helper::ParseFloat($request->get('purchase_cost')) : '';
            ($request->has('purchase_date')) ?
                $asset->purchase_date = $request->get('purchase_date') : '';
            ($request->has('assigned_to')) ?
                $asset->assigned_to = $request->get('assigned_to') : '';
            ($request->has('supplier_id')) ?
                $asset->supplier_id = $request->get('supplier_id') : '';
            ($request->has('requestable')) ?
                $asset->requestable = $request->get('requestable') : '';
            ($request->has('rtd_location_id')) ?
                $asset->rtd_location_id = $request->get('rtd_location_id') : '';
            ($request->has('company_id')) ?
                $asset->company_id = Company::getIdForCurrentUser($request->get('company_id')) : '';

            if ($request->has('model_id')) {
                if (($model = AssetModel::find($request->get('model_id'))) && (isset($model->fieldset))) {
                    foreach ($model->fieldset->fields as $field) {
                        if ($request->has($field->convertUnicodeDbSlug())) {
                            $asset->{$field->convertUnicodeDbSlug()} = e($request->input($field->convertUnicodeDbSlug()));
                        }
                    }
                }
            }

            if ($asset->save()) {

                if ($request->get('assigned_user')) {
                    $target = User::find(request('assigned_user'));
                } elseif ($request->get('assigned_asset')) {
                    $target = Asset::find(request('assigned_asset'));
                } elseif ($request->get('assigned_location')) {
                    $target = Location::find(request('assigned_location'));
                }

                if (isset($target)) {
                    $asset->checkOut($target, Auth::user(), date('Y-m-d H:i:s'), '', 'Checked out on asset update', e($request->get('name')));
                }

                return response()->json(Helper::formatStandardApiResponse('success', $asset, trans('admin/hardware/message.update.success')));
            }
            return response()->json(Helper::formatStandardApiResponse('error', null, $asset->getErrors()), 200);
        }
        return response()->json(Helper::formatStandardApiResponse('error', null, trans('admin/hardware/message.does_not_exist')), 200);
    }


    /**
     * Delete a given asset (mark as deleted).
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @param int $assetId
     * @since [v4.0]
     * @return JsonResponse
     */
    public function destroy($id)
    {
        $this->authorize('delete', Asset::class);

        if ($asset = Asset::find($id)) {

            $this->authorize('delete', $asset);

            DB::table('assets')
                ->where('id', $asset->id)
                ->update(array('assigned_to' => null));

            $asset->delete();

            return response()->json(Helper::formatStandardApiResponse('success', null, trans('admin/hardware/message.delete.success')));
        }

        return response()->json(Helper::formatStandardApiResponse('error', null, trans('admin/hardware/message.does_not_exist')), 200);
    }



    /**
     * Checkout an asset
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @param int $assetId
     * @since [v4.0]
     * @return JsonResponse
     */
    public function checkout(Request $request, $asset_id)
    {
        $this->authorize('checkout', Asset::class);
        $asset = Asset::findOrFail($asset_id);

        if (!$asset->availableForCheckout()) {
            return response()->json(Helper::formatStandardApiResponse('error', ['asset'=> e($asset->asset_tag)], trans('admin/hardware/message.checkout.not_available')));
        }

        $this->authorize('checkout', $asset);

        if ($request->has('user_id')) {
            $target = User::find($request->input('user_id'));
        } elseif ($request->has('asset_id')) {
            $target = Asset::find($request->input('asset_id'));
        } elseif ($request->has('location_id')) {
            $target = Location::find($request->input('location_id'));
        }

        if (!isset($target)) {
            return response()->json(Helper::formatStandardApiResponse('error', ['asset'=> e($asset->asset_tag)], 'No valid checkout target specified for asset '.e($asset->asset_tag).'.'));
        }

        $checkout_at = request('checkout_at', date("Y-m-d H:i:s"));
        $expected_checkin = request('expected_checkin', null);
        $note = request('note', null);
        $asset_name = request('name', null);
        

        if ($asset->checkOut($target, Auth::user(), $checkout_at, $expected_checkin, $note, $asset_name)) {
            return response()->json(Helper::formatStandardApiResponse('success', ['asset'=> e($asset->asset_tag)], trans('admin/hardware/message.checkout.success')));
        }

        return response()->json(Helper::formatStandardApiResponse('error', ['asset'=> e($asset->asset_tag)], trans('admin/hardware/message.checkout.error')))->withErrors($asset->getErrors());
    }


    /**
     * Checkin an asset
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @param int $assetId
     * @since [v4.0]
     * @return JsonResponse
     */
    public function checkin($asset_id)
    {
        $this->authorize('checkin', Asset::class);
        $asset = Asset::findOrFail($asset_id);
        $this->authorize('checkin', $asset);


        $user = $asset->assignedUser;
        if (is_null($target = $asset->assignedTo)) {
            return response()->json(Helper::formatStandardApiResponse('error', ['asset'=> e($asset->asset_tag)], trans('admin/hardware/message.checkin.already_checked_in')));
        }

        $asset->expected_checkin = null;
        $asset->last_checkout = null;
        $asset->assigned_to = null;
        $asset->assignedTo()->disassociate($asset);
        $asset->accepted = null;
        $asset->name = e(Input::get('name'));

        if (Input::has('status_id')) {
            $asset->status_id =  e(Input::get('status_id'));
        }

        // Was the asset updated?
        if ($asset->save()) {
            $logaction = $asset->logCheckin($target, e(request('note')));

            $data['log_id'] = $logaction->id;
            $data['first_name'] = get_class($target) == User::class ? $target->first_name : '';
            $data['item_name'] = $asset->present()->name();
            $data['checkin_date'] = $logaction->created_at;
            $data['item_tag'] = $asset->asset_tag;
            $data['item_serial'] = $asset->serial;
            $data['note'] = $logaction->note;

            if ((($asset->checkin_email()=='1')) && (isset($user)) && (!config('app.lock_passwords'))) {
                Mail::send('emails.checkin-asset', $data, function ($m) use ($user) {
                    $m->to($user->email, $user->first_name . ' ' . $user->last_name);
                    $m->replyTo(config('mail.reply_to.address'), config('mail.reply_to.name'));
                    $m->subject(trans('mail.Confirm_Asset_Checkin'));
                });
            }

            return response()->json(Helper::formatStandardApiResponse('success', ['asset'=> e($asset->asset_tag)], trans('admin/hardware/message.checkin.success')));
        }

        return response()->json(Helper::formatStandardApiResponse('success', ['asset'=> e($asset->asset_tag)], trans('admin/hardware/message.checkin.error')));
    }


    /**
     * Mark an asset as audited
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @param int $id
     * @since [v4.0]
     * @return JsonResponse
     */
    public function audit(Request $request) {


        $this->authorize('audit', Asset::class);
        $rules = array(
            'asset_tag' => 'required',
            'location_id' => 'exists:locations,id|nullable|numeric',
            'next_audit_date' => 'date|nullable'
        );

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json(Helper::formatStandardApiResponse('error', null, $validator->errors()->all()));
        }

        $asset = Asset::where('asset_tag','=', $request->input('asset_tag'))->first();


        if ($asset) {
            $asset->next_audit_date = $request->input('next_audit_date');
            if ($asset->save()) {
                $log = $asset->logAudit(request('note'),request('location_id'));
                return response()->json(Helper::formatStandardApiResponse('success', [
                    'asset_tag'=> e($asset->asset_tag),
                    'note'=> e($request->input('note')),
                    'next_audit_date' => Helper::getFormattedDateObject($log->calcNextAuditDate())
                ], trans('admin/hardware/message.audit.success')));
            }
        }

        return response()->json(Helper::formatStandardApiResponse('error', ['asset_tag'=> e($request->input('asset_tag'))], 'Asset with tag '.$request->input('asset_tag').' not found'));





    }
}
