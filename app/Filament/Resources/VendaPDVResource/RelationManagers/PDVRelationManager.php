<?php

namespace App\Filament\Resources\VendaPDVResource\RelationManagers;

use App\Models\Produto;
use App\Models\VendaPDV;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Table;

class PDVRelationManager extends RelationManager
{
    protected static string $relationship = 'PDV';

    protected static ?string $title = 'Itens da Venda PDV';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
            Forms\Components\Select::make('produto_id')
                ->label('Produto')
                ->options(Produto::all()->pluck('nome', 'id'))
                ->searchable()
                ->required()
                ->live(onBlur: true)
                ->afterStateUpdated(function ($state, callable $set) {
                    if ($state) {
                        $produto = Produto::find($state);
                        if ($produto) {
                            $set('valor_venda', $produto->valor_venda);
                        }
                    } else {
                        $set('valor_venda', null);
                    }
                }),
            Forms\Components\TextInput::make('qtd')
                ->label('Quantidade')
                ->numeric()
                ->required()
                ->live(onBlur: true)
                ->afterStateUpdated(function ($state, callable $set, callable $get) {
                    $valorVenda = $get('valor_venda') ?? 0;
                    $set('sub_total', (float)$state * (float)$valorVenda);
                }),
            Forms\Components\TextInput::make('valor_venda')
                ->label('Valor Unitário')
                ->numeric()
                ->required()
                ->live(onBlur: true)
                ->afterStateUpdated(function ($state, callable $set, callable $get) {
                    $qtd = $get('qtd') ?? 0;
                    $set('sub_total', (float)$qtd * (float)$state);
                }),
            Forms\Components\TextInput::make('sub_total')
                ->label('Sub-Total')
                ->numeric()
                ->required()
                ->readonly(),


            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('vendapdv_id')
            ->columns([
                Tables\Columns\TextColumn::make('venda_p_d_v_id')
                    ->label('Venda PDV'),
                Tables\Columns\TextColumn::make('produto.nome'),
                Tables\Columns\TextColumn::make('produto.codbar')
                    ->label('Código do Produto'),
                Tables\Columns\TextColumn::make('qtd')
                    ->summarize(Sum::make()->label('Qtd de Produtos')),
                Tables\Columns\TextColumn::make('valor_venda')
                    ->money('BRL')
                    ->label('Valor Unit.'),
                Tables\Columns\TextColumn::make('sub_total')
                    ->summarize(Sum::make()->money('BRL')->label('Total'))
                    ->money('BRL')          
                    ->label('Sub-Total'),
            ])
            ->filters([
                Tables\Filters\Filter::make('produto_nome')
                    ->form([
                        Forms\Components\TextInput::make('produto_nome')->label('Nome do Produto'),
                    ])
                    ->query(function ($query, $data) {
                        if ($data['produto_nome']) {
                            $query->whereHas('produto', function ($q) use ($data) {
                                $q->where('nome', 'like', '%' . $data['produto_nome'] . '%');
                            });
                        }
                    }),
                Tables\Filters\Filter::make('data_venda')
                    ->form([
                        Forms\Components\DatePicker::make('data_venda')->label('Data da Venda'),
                    ])
                    ->query(function ($query, $data) {
                        if ($data['data_venda']) {
                            $query->whereDate('created_at', $data['data_venda']);
                        }
                    }),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Adicionar Item')
                    ->icon('heroicon-o-plus')
                    ->modalHeading('Adicionar Item à Venda')
                    ->visible(fn ($livewire) => $livewire->ownerRecord->tipo_registro == 'orcamento')
                    ->after(function ($data, $record, $livewire) {
                        $venda = VendaPDV::find($record->venda_p_d_v_id);
                        // Soma todos os sub_totals dos itens da venda
                        $novoValorTotal = $venda->PDV()->sum('sub_total');
                        $venda->valor_total = $novoValorTotal;
                        $venda->valor_total_desconto = $novoValorTotal;

                        // Remove todos os descontos aplicados
                        $venda->tipo_acres_desc = null;
                        $venda->valor_acres_desc = null;
                        $venda->percent_acres_desc = null;

                        $venda->save();
                        return redirect(request()->header('Referer'));
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->visible(fn ($livewire) => $livewire->ownerRecord->tipo_registro == 'orcamento')
                    ->after(function ($data, $record, $livewire) {
                        $venda = VendaPDV::find($record->venda_p_d_v_id);
                        // Soma todos os sub_totals dos itens da venda
                        $novoValorTotal = $venda->PDV()->sum('sub_total');
                        $venda->valor_total = $novoValorTotal;
                        $venda->valor_total_desconto = $novoValorTotal;

                        // Remove todos os descontos aplicados
                        $venda->tipo_acres_desc = null;
                        $venda->valor_acres_desc = null;
                        $venda->percent_acres_desc = null;

                        $venda->save();
                        return redirect(request()->header('Referer'));
                    }),
                   
                Tables\Actions\DeleteAction::make()
                ->before(function ($data, $record) {
                    $produto = Produto::find($record->produto_id);
                    $venda   = VendaPDV::find($record->venda_p_d_v_id);
                    $venda->valor_total -= $record->sub_total;
                    $produto->estoque += ($record->qtd);
                    $venda->save();
                    $produto->save();
                })
                ->after(function () {
                    return redirect(request()->header('Referer'));
                }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->before(function ($records) {
                            foreach ($records as $record) {
                                $produto = Produto::find($record->produto_id);
                                $venda   = VendaPDV::find($record->venda_p_d_v_id);
                                $venda->valor_total -= $record->sub_total;
                                $produto->estoque += ($record->qtd);
                                $venda->save();
                                $produto->save();
                            }

                        })
                        ->after(function () {
                            return redirect(request()->header('Referer'));
                        }),

                ]),
            ]);
    }
}
